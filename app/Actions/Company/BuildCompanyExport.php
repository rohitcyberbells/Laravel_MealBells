<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\Skip;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use ZipArchive;

/**
 * Everything one company holds, as CSV inside a zip.
 *
 * Written for the person who has to read it in a spreadsheet, not for a
 * machine: one file per thing, a header row that says what each column is, and
 * a README naming the company, the moment it was taken and what each file
 * covers. An export nobody can interpret six months later is not an export.
 *
 * Strictly one company. Every query is scoped on company_id, and a test aims
 * the export at a tenant with a neighbour to prove nothing of the neighbour's
 * appears.
 */
class BuildCompanyExport
{
    /**
     * Rows are streamed to the CSV in chunks rather than collected, so a
     * company with years of history does not have to fit in memory.
     */
    protected const CHUNK = 500;

    /**
     * @return string the path of the zip written to local temporary storage
     */
    public function execute(Company $company, ?string $generatedBy = null): string
    {
        $directory = storage_path('app/exports');

        if (! is_dir($directory) && ! mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory} to write the export into.");
        }

        $stamp = now()->format('Y-m-d-His');
        $slug = str($company->code ?: $company->name)->slug()->value();
        $zipPath = "{$directory}/mealbells-{$slug}-{$stamp}.zip";

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$zipPath} for writing.");
        }

        $zip->addFromString('README.txt', $this->readme($company, $generatedBy));

        foreach ($this->files($company) as $name => [$headers, $query, $mapper]) {
            $zip->addFromString($name, $this->csv($headers, $query, $mapper));
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: Builder, 2: callable}>
     */
    protected function files(Company $company): array
    {
        return [
            'employees.csv' => [
                ['employee_code', 'name', 'email', 'status', 'meal_eligible', 'attendance_source', 'has_login', 'hrms_external_id', 'created_at'],
                Employee::where('company_id', $company->id)->orderBy('employee_code'),
                fn (Employee $e) => [
                    $e->employee_code, $e->name, $e->email, $e->status,
                    $e->is_meal_eligible ? 'yes' : 'no',
                    $e->attendance_source, $e->user_id ? 'yes' : 'no',
                    $e->external_id, $e->created_at?->toDateTimeString(),
                ],
            ],

            'skips.csv' => [
                ['date', 'employee_code', 'employee_name', 'source', 'reason', 'recorded_at', 'cancelled_at', 'cancelled_source', 'external_ref'],
                Skip::with('employee:id,employee_code,name')->where('company_id', $company->id)->orderBy('date'),
                fn (Skip $s) => [
                    $s->date instanceof Carbon ? $s->date->toDateString() : $s->date,
                    $s->employee?->employee_code, $s->employee?->name,
                    $s->source, $s->reason,
                    $s->created_at?->toDateTimeString(),
                    $s->cancelled_at?->toDateTimeString(), $s->cancelled_source,
                    $s->external_ref,
                ],
            ],

            'extra_meals.csv' => [
                ['date', 'quantity', 'type', 'reason', 'recorded_at', 'cancelled_at'],
                MealAdjustment::where('company_id', $company->id)->orderBy('date'),
                fn (MealAdjustment $a) => [
                    $a->date instanceof Carbon ? $a->date->toDateString() : $a->date,
                    $a->quantity, $a->type, $a->reason,
                    $a->created_at?->toDateTimeString(),
                    $a->cancelled_at?->toDateTimeString(),
                ],
            ],

            // The numbers the vendor was actually given, which is what a billing
            // question is about. adjusted_total is included because the locked
            // figure and the figure after post-cutoff changes are different
            // answers to different questions.
            'daily_counts.csv' => [
                ['date', 'base_eligible', 'skips', 'extras', 'final_expected', 'adjusted_total', 'status', 'lock_type', 'locked_at'],
                MealCount::where('company_id', $company->id)->orderBy('date'),
                fn (MealCount $c) => [
                    $c->date instanceof Carbon ? $c->date->toDateString() : $c->date,
                    $c->base_eligible_count, $c->skip_count, $c->extra_count,
                    $c->final_expected_count, $c->adjusted_total,
                    $c->status, $c->lock_type, $c->locked_at?->toDateTimeString(),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $headers
     */
    protected function csv(array $headers, Builder $query, callable $mapper): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $headers);

        $query->chunk(self::CHUNK, function ($rows) use ($handle, $mapper) {
            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    fn ($value) => $value === null ? '' : $value,
                    $mapper($row),
                ));
            }
        });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    protected function readme(Company $company, ?string $generatedBy): string
    {
        $lines = [
            'MealBells export',
            '================',
            '',
            'Company:      '.$company->name.' ('.$company->code.')',
            'Taken at:     '.now()->toDateTimeString().' UTC',
            'Taken by:     '.($generatedBy ?? 'not recorded'),
            '',
            'Files',
            '-----',
            'employees.csv     One row per employee, including those who have left',
            '                  (see the status column) and those with no login.',
            'skips.csv         One row per meal skipped, including cancelled ones -',
            '                  cancelled_at tells them apart. source says who or what',
            '                  entered it: self, hr, recurring, leave, wfh or link.',
            'extra_meals.csv   Guest and visitor meals added on top of the count.',
            'daily_counts.csv  What the kitchen was told for each day.',
            '                  final_expected is the figure at the moment the day',
            '                  locked. adjusted_total includes post-cutoff changes',
            '                  made after the vendor had already been given a number,',
            '                  so the two differ on any day that changed late.',
            '',
            'Notes',
            '-----',
            'Dates are the company\'s local dates. Timestamps are UTC.',
            'An employee whose personal data has been removed on request appears',
            'with an anonymised code and no name or address; their skips are still',
            'counted, so the daily totals stay correct.',
            'This file contains personal data. Treat it accordingly.',
        ];

        return implode("\n", $lines)."\n";
    }
}
