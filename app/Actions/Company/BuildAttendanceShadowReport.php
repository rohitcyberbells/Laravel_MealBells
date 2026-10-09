<?php

namespace App\Actions\Company;

use App\Models\AttendanceDay;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Skip;
use App\Services\MealCalendar;
use Carbon\Carbon;

/**
 * What attendance would have changed, had it been allowed to.
 *
 * The whole output of Phase 1: a number per day, and a total, so that deciding
 * whether to act on attendance is a decision about evidence rather than a
 * guess. See docs/attendance-design.md.
 *
 * Reads only. Nothing here can alter a count, and the figures it reports beside
 * each day are the real ones, taken from the locked snapshot where there is
 * one - so a reader can see what was ordered next to what would have been.
 */
class BuildAttendanceShadowReport
{
    /**
     * @return array<string, mixed>
     */
    public function execute(Company $company, string $fromDate, string $toDate): array
    {
        $timezone = $company->setting?->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');

        $attendance = AttendanceDay::where('company_id', $company->id)
            ->whereBetween('date', [$fromDate, $toDate])
            ->get()
            ->groupBy(fn (AttendanceDay $day) => Carbon::parse($day->date)->toDateString());

        $skipsByDate = Skip::where('company_id', $company->id)
            ->whereBetween('date', [$fromDate, $toDate])
            ->whereNull('cancelled_at')
            ->get(['employee_id', 'date'])
            ->groupBy(fn (Skip $skip) => Carbon::parse($skip->date)->toDateString());

        // The base is today's eligible headcount, which is the same figure the
        // engine uses. It is not a historical reconstruction, and the screen
        // says so rather than implying otherwise.
        $eligible = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('is_meal_eligible', true)
            ->count();

        $readFor = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('is_meal_eligible', true)
            ->where('attendance_source', 'integrated')
            ->count();

        $days = [];
        $cursor = Carbon::parse($fromDate);
        $end = Carbon::parse($toDate);

        MealCalendar::preload($company, $fromDate, $toDate);

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $cursor->addDay();

            if (! MealCalendar::isMealDay($company, $date)) {
                continue;
            }

            $rows = $attendance->get($date);

            if ($rows === null || $rows->isEmpty()) {
                $days[] = [
                    'date' => $date,
                    'day_name' => Carbon::parse($date)->format('D'),
                    'read' => false,
                    'present' => 0,
                    'absent' => 0,
                    'unknown' => 0,
                    'already_not_eating' => 0,
                    'would_be_absent' => 0,
                ];

                continue;
            }

            $skipped = ($skipsByDate->get($date) ?? collect())->pluck('employee_id')->all();

            // whereStrict, not where. A collection's where() compares loosely
            // and PHP holds that null == false, so every unknown answer was
            // being counted as an absence - which is precisely the distinction
            // this whole design rests on. Found by the report test.
            $absent = $rows->whereStrict('clocked_in_by_cutoff', false);

            $days[] = [
                'date' => $date,
                'day_name' => Carbon::parse($date)->format('D'),
                'read' => true,
                'present' => $rows->whereStrict('clocked_in_by_cutoff', true)->count(),
                'absent' => $absent->count(),
                'unknown' => $rows->whereNull('clocked_in_by_cutoff')->count(),
                'already_not_eating' => count($skipped),
                // Absent and not already accounted for by some other source.
                'would_be_absent' => $absent
                    ->reject(fn (AttendanceDay $day) => in_array($day->employee_id, $skipped, true))
                    ->count(),
            ];
        }

        $readDays = array_values(array_filter($days, fn (array $day) => $day['read']));
        $totalWouldBeAbsent = array_sum(array_column($readDays, 'would_be_absent'));

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'timezone' => $timezone,
            'enabled' => (bool) $company->setting?->attendance_absence_enabled,
            'eligible_employees' => $eligible,
            'employees_read' => $readFor,
            'days' => $days,
            'days_read' => count($readDays),
            'meal_days' => count($days),
            'total_would_be_absent' => $totalWouldBeAbsent,
            // The figure the decision turns on. An average over the days
            // actually read, not over the range - a range with three unread
            // days would otherwise understate it.
            'average_per_day' => count($readDays) > 0
                ? round($totalWouldBeAbsent / count($readDays), 1)
                : null,
        ];
    }
}
