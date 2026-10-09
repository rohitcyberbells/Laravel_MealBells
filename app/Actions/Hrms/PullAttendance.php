<?php

namespace App\Actions\Hrms;

use App\Models\AttendanceDay;
use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\Employee;
use App\Models\Skip;
use App\Services\Hrms\Adapters\CyberPulseAdapter;
use App\Services\Hrms\CyberPulse\CyberPulseClient;
use App\Services\Hrms\HrmsEventMapper;
use App\Services\MealCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads one day of attendance and records what it saw. Shadow mode.
 *
 * This action creates no skip, touches no meal count, and changes nothing the
 * kitchen receives. Its entire output is a row per employee in
 * `attendance_days` and a summary of what it *would* have changed if attendance
 * were allowed to affect the count - which is the number that decides whether
 * Phase 2 is worth its risk.
 *
 * See docs/attendance-design.md. The guards below are the design, not
 * defensiveness: attendance is the only mechanism that acts on today, because
 * the leave pull deliberately ignores today, so its fail-safes carry the whole
 * weight.
 */
class PullAttendance
{
    public function __construct(
        protected CyberPulseClient $client,
        protected CyberPulseAdapter $adapter,
        protected HrmsEventMapper $mapper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(Company $company, ?string $date = null, bool $dryRun = false): array
    {
        $setting = $company->setting;
        $timezone = $setting?->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');
        $date ??= Carbon::today($timezone)->toDateString();

        $summary = [
            'company_id' => $company->id,
            'date' => $date,
            'dry_run' => $dryRun,
            'ok' => false,
            'status' => 'skipped',
            'reason' => null,
            'eligible' => 0,
            'present' => 0,
            'absent' => 0,
            'unknown' => 0,
            'unmatched' => 0,
            'unmatched_references' => [],
            'would_be_absent' => 0,
            'already_not_eating' => 0,
            'warnings' => [],
        ];

        if (! $setting?->attendance_absence_enabled) {
            $summary['reason'] = 'Attendance is not enabled for this company.';

            return $summary;
        }

        if (! MealCalendar::isMealDay($company, $date)) {
            $summary['reason'] = "{$date} is not a meal day for this company.";

            return $summary;
        }

        $connection = CompanyHrmsConnection::where('company_id', $company->id)->first();

        if (! $connection || ! $connection->hasAttendanceCredentials()) {
            $summary['reason'] = 'No attendance API key is configured for this company.';

            return $summary;
        }

        $connection->setRelation('company', $company);

        $result = $this->client->fetchAttendance($connection, $date);

        if (! $result->ok) {
            // A failed fetch looks exactly like everybody being absent. Nothing
            // is recorded, so the report cannot mistake an outage for a day
            // when nobody came in.
            $summary['status'] = 'error';
            $summary['reason'] = $result->error;

            $this->record($connection, $summary, $dryRun);

            return $summary;
        }

        /** @var array<int, Employee> $eligible */
        $eligible = $this->eligibleEmployees($company);
        $summary['eligible'] = count($eligible);

        if ($summary['eligible'] === 0) {
            $summary['reason'] = 'No employees are set to have their attendance read.';
            $this->record($connection, $summary, $dryRun);

            return $summary;
        }

        if ($result->leaves === []) {
            // An empty response is not "nobody came in" - it is the HR system
            // telling us nothing, which is the same as a failed fetch.
            $summary['status'] = 'suspicious';
            $summary['reason'] = 'CyberPulse returned no employees at all.';
            $summary['warnings'][] = 'Recorded nothing: the attendance response was empty.';

            $this->record($connection, $summary, $dryRun);

            return $summary;
        }

        [$decisions, $unmatched] = $this->resolve($company, $result->leaves, $date, $timezone, $setting->cutoff_time);

        $summary['unmatched'] = count($unmatched);
        // References only, never a name or an address: this list is shown on a
        // screen and written to a summary column.
        $summary['unmatched_references'] = array_slice($unmatched, 0, 20);

        foreach ($decisions as $decision) {
            match ($decision['clocked_in_by_cutoff']) {
                true => $summary['present']++,
                false => $summary['absent']++,
                default => $summary['unknown']++,
            };
        }

        // Employees the HR system said nothing about at all. Counted as unknown
        // rather than absent: silence is not evidence.
        $accountedFor = count($decisions);
        $summary['unknown'] += max(0, $summary['eligible'] - $accountedFor);

        $share = $summary['eligible'] > 0 ? $summary['absent'] / $summary['eligible'] : 0.0;
        $maxShare = (float) config('hrms.cyberpulse.max_absent_share', 0.4);

        if ($share > $maxShare) {
            $percent = round($share * 100);
            $limit = round($maxShare * 100);

            $summary['status'] = 'suspicious';
            $summary['reason'] = "{$percent}% of eligible employees look absent, over the {$limit}% limit.";
            $summary['warnings'][] = "Recorded nothing: {$percent}% absent is more likely a problem at the HR system than a real day.";
            $summary['absent'] = 0;
            $summary['present'] = 0;
            $summary['unknown'] = 0;

            $this->record($connection, $summary, $dryRun);

            return $summary;
        }

        $summary['already_not_eating'] = $this->existingSkipCount($company, $date);
        $summary['would_be_absent'] = $this->wouldBeAbsent($company, $date, $decisions);

        if (! $dryRun) {
            $this->store($company, $date, $decisions);
        }

        $summary['ok'] = true;
        $summary['status'] = $summary['warnings'] === [] ? 'ok' : 'suspicious';

        $this->record($connection, $summary, $dryRun);

        return $summary;
    }

    /**
     * Employees whose attendance we are allowed to read.
     *
     * `attendance_source` has existed on the employee record all along without
     * meaning anything. This gives it one: only `integrated` employees are
     * looked up, so an employee whose attendance is tracked on paper, or not at
     * all, is simply absent from this feature.
     *
     * @return array<int, Employee>
     */
    protected function eligibleEmployees(Company $company): array
    {
        return Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('is_meal_eligible', true)
            ->where('attendance_source', 'integrated')
            ->get()
            ->all();
    }

    /**
     * Vendor rows onto our employees, dropping the ones we cannot place.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    protected function resolve(Company $company, array $rows, string $date, string $timezone, ?string $cutoffTime): array
    {
        $decisions = [];
        $unmatched = [];

        foreach ($rows as $row) {
            $decision = $this->adapter->toAttendance(
                $row,
                $date,
                $timezone,
                $cutoffTime ?? '11:00:00',
            );

            if ($decision === null) {
                continue;
            }

            $employee = $this->mapper->findEmployee($company, $decision['reference'], $decision['email']);

            if ($employee === null) {
                $unmatched[] = $decision['reference'] !== '' ? $decision['reference'] : (string) $decision['email'];

                continue;
            }

            // Someone the HR system tracks but we do not read attendance for -
            // on paper, or not at all. Not an absence and not a mystery.
            if ($employee->attendance_source !== 'integrated'
                || $employee->status !== 'active'
                || ! $employee->is_meal_eligible) {
                continue;
            }

            $decisions[] = [
                'employee_id' => $employee->id,
                'clocked_in_by_cutoff' => $decision['clocked_in_by_cutoff'],
                'is_wfh' => $decision['is_wfh'],
            ];
        }

        return [$decisions, array_values(array_unique($unmatched))];
    }

    /**
     * How many meals we would not have ordered.
     *
     * Known absences only - never an unknown - and only where the person is not
     * already not eating for some other reason. Somebody on approved leave is
     * attributed to leave; counting them here as well would double the saving
     * and overstate the case for Phase 2.
     *
     * @param  array<int, array<string, mixed>>  $decisions
     */
    protected function wouldBeAbsent(Company $company, string $date, array $decisions): int
    {
        $absentIds = array_values(array_map(
            fn (array $decision) => $decision['employee_id'],
            array_filter($decisions, fn (array $decision) => $decision['clocked_in_by_cutoff'] === false),
        ));

        if ($absentIds === []) {
            return 0;
        }

        $alreadySkipped = Skip::where('company_id', $company->id)
            ->where('date', $date)
            ->whereNull('cancelled_at')
            ->whereIn('employee_id', $absentIds)
            ->pluck('employee_id')
            ->all();

        return count(array_diff($absentIds, $alreadySkipped));
    }

    protected function existingSkipCount(Company $company, string $date): int
    {
        return Skip::where('company_id', $company->id)
            ->where('date', $date)
            ->whereNull('cancelled_at')
            ->count();
    }

    /**
     * One row per employee per day, updated rather than added to - which is
     * what makes a second run on the same day a no-op.
     *
     * @param  array<int, array<string, mixed>>  $decisions
     */
    protected function store(Company $company, string $date, array $decisions): void
    {
        DB::transaction(function () use ($company, $date, $decisions) {
            foreach ($decisions as $decision) {
                AttendanceDay::updateOrCreate(
                    ['employee_id' => $decision['employee_id'], 'date' => $date],
                    [
                        'company_id' => $company->id,
                        'clocked_in_by_cutoff' => $decision['clocked_in_by_cutoff'],
                        'is_wfh' => $decision['is_wfh'],
                        'source' => 'cyberpulse',
                    ],
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function record(CompanyHrmsConnection $connection, array $summary, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $connection->forceFill([
            'last_attendance_pull_at' => now(),
            'last_attendance_pull_status' => $summary['status'],
            'last_attendance_pull_error' => $summary['status'] === 'error' ? $summary['reason'] : null,
            'last_attendance_pull_summary' => $summary,
        ])->save();
    }
}
