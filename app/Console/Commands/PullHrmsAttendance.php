<?php

namespace App\Console\Commands;

use App\Actions\Hrms\PullAttendance;
use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\CompanySetting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reads who had clocked in by the cutoff, and reports what that would have
 * changed. Shadow mode: no skip is written and no count moves.
 *
 * All of the work is in the action, so the scheduler and this command exercise
 * exactly the same path. --dry-run additionally skips the attendance_days
 * write, which is the safe way to point this at a live HR system the first
 * time.
 */
class PullHrmsAttendance extends Command
{
    protected $signature = 'hrms:pull-attendance
                            {company? : Company code; optional when exactly one company has attendance enabled}
                            {--date= : The day to read, default today in the company timezone}
                            {--all : Every company with attendance enabled, for the scheduler}
                            {--before-cutoff : With --all, only companies entering their pre-cutoff window}
                            {--dry-run : Report without recording anything}';

    protected $description = 'Read attendance and report who would not have been counted (changes no count)';

    public function handle(PullAttendance $pull): int
    {
        if ($this->option('all')) {
            return $this->pullEveryCompany($pull);
        }

        $company = $this->resolveCompany();

        if (! $company) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $date = $this->option('date');

        $this->line("Reading attendance for {$company->name} ({$company->code})".($dryRun ? ' - dry run, nothing will be recorded' : ''));
        $this->comment('Shadow mode: this changes no meal count and creates no skip.');
        $this->newLine();

        $summary = $pull->execute($company, $date, $dryRun);

        if ($summary['status'] === 'skipped') {
            $this->warn($summary['reason'] ?? 'Nothing to do.');

            return self::SUCCESS;
        }

        if ($summary['status'] === 'error') {
            $this->error($summary['reason'] ?? 'The attendance read failed.');
            $this->line('Nothing was recorded. A failed fetch looks exactly like everybody being absent.');

            return self::FAILURE;
        }

        $this->table(['', 'Count'], [
            ['Eligible employees (attendance read)', $summary['eligible']],
            ['Clocked in by cutoff', $summary['present']],
            ['Absent by cutoff', $summary['absent']],
            ['Unknown (no answer from the HR system)', $summary['unknown']],
            ['Not matched to an employee', $summary['unmatched']],
            ['Already not eating (leave, WFH, self, HR)', $summary['already_not_eating']],
            ['WOULD-BE ABSENT (meals not ordered, if enabled)', $summary['would_be_absent']],
        ]);

        foreach ($summary['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($summary['unmatched'] > 0) {
            $this->newLine();
            $this->warn($summary['unmatched'].' attendance row(s) matched no employee in MealBells:');

            foreach ($summary['unmatched_references'] as $reference) {
                $this->line('  '.$reference);
            }

            $this->line('Set external_id or email on the matching employee, then run again.');
            $this->line('Until then those people are treated as present, so no meal is lost.');
        }

        $this->newLine();
        $this->line($summary['status'] === 'suspicious'
            ? '<comment>Marked suspicious - a safety guard stopped it recording anything.</comment>'
            : '<info>Read completed. No count was changed.</info>');

        return self::SUCCESS;
    }

    /**
     * The scheduler's path. One company failing does not stop the rest, and the
     * exit code reflects whether any did, so a cron reporting non-zero is
     * telling the truth.
     */
    protected function pullEveryCompany(PullAttendance $pull): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $failed = 0;
        $ran = 0;

        foreach ($this->enabledCompanies() as $company) {
            if ($this->option('before-cutoff') && ! $this->isDueBeforeCutoff($company)) {
                continue;
            }

            $ran++;
            $summary = $pull->execute($company, $this->option('date'), $dryRun);

            if ($summary['status'] === 'skipped') {
                continue;
            }

            $line = "{$company->code}: absent {$summary['absent']}, would-be absent {$summary['would_be_absent']}, unknown {$summary['unknown']}, unmatched {$summary['unmatched']}";

            if ($summary['status'] === 'error') {
                $failed++;
                $this->error("{$company->code}: {$summary['reason']}");
            } elseif ($summary['status'] === 'suspicious') {
                $this->warn($line.' - marked suspicious');
            } else {
                $this->info($line);
            }
        }

        if ($ran === 0) {
            $this->line('No company was due an attendance read.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Company>
     */
    protected function enabledCompanies(): Collection
    {
        $companyIds = CompanySetting::where('attendance_absence_enabled', true)->pluck('company_id');

        return Company::with('setting')->whereIn('id', $companyIds)->get();
    }

    /**
     * Whether this company is inside its pre-cutoff window and has not been
     * read in it yet.
     *
     * Once per window, not once per minute: the scheduler runs every minute so
     * that a company whose cutoff is at 11:00 is read promptly, not so that it
     * is read five times.
     */
    protected function isDueBeforeCutoff(Company $company): bool
    {
        $setting = $company->setting;

        if (! $setting) {
            return false;
        }

        $timezone = $setting->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');
        $lead = (int) config('hrms.cyberpulse.attendance_before_cutoff_minutes', 5);

        [$hour, $minute] = $setting->cutoffHourMinute();

        $cutoff = Carbon::today($timezone)->setTime($hour, $minute);
        $windowOpens = $cutoff->copy()->subMinutes($lead);
        $now = Carbon::now($timezone);

        if ($now->lt($windowOpens) || $now->gte($cutoff)) {
            return false;
        }

        $connection = CompanyHrmsConnection::where('company_id', $company->id)->first();
        $lastRun = $connection?->last_attendance_pull_at;

        return $lastRun === null || $lastRun->setTimezone($timezone)->lt($windowOpens);
    }

    protected function resolveCompany(): ?Company
    {
        $code = $this->argument('company');

        if ($code) {
            $company = Company::with('setting')->where('code', $code)->first();

            if (! $company) {
                $this->error("No company with code {$code}.");

                return null;
            }

            return $company;
        }

        $companies = $this->enabledCompanies();

        if ($companies->count() === 1) {
            return $companies->first();
        }

        $this->error($companies->isEmpty()
            ? 'No company has attendance enabled. Turn it on in company settings first.'
            : 'More than one company has attendance enabled; name one by its code.');

        return null;
    }
}
