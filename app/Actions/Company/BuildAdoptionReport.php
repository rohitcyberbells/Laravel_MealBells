<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Skip;
use App\Services\MealCalendar;
use Carbon\Carbon;

class BuildAdoptionReport
{
    public function execute(Company $company, string $fromDate, string $toDate): array
    {
        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';

        $start = Carbon::createFromFormat('Y-m-d', $fromDate, $timezone);
        $end = Carbon::createFromFormat('Y-m-d', $toDate, $timezone);

        $dailySeries = [];
        $skipsBySource = [
            'hr' => 0,
            'leave' => 0,
            'wfh' => 0,
            'self' => 0,
            'link' => 0,
            'recurring' => 0,
        ];

        $daysWith20PlusHrSkips = 0;
        $totalActiveSkips = 0;

        $current = $start->copy();
        while ($current->lte($end)) {
            $dateStr = $current->toDateString();

            if (MealCalendar::isMealDay($company, $dateStr)) {
                $daySkips = Skip::where('company_id', $company->id)
                    ->where('date', $dateStr)
                    ->whereNull('cancelled_at')
                    ->get();

                $hrCount = $daySkips->where('source', 'hr')->count();
                $leaveCount = $daySkips->where('source', 'leave')->count();
                $wfhCount = $daySkips->where('source', 'wfh')->count();
                $selfCount = $daySkips->where('source', 'self')->count();
                $linkCount = $daySkips->where('source', 'link')->count();
                $recurringCount = $daySkips->where('source', 'recurring')->count();

                $skipsBySource['hr'] += $hrCount;
                $skipsBySource['leave'] += $leaveCount;
                $skipsBySource['wfh'] += $wfhCount;
                $skipsBySource['self'] += $selfCount;
                $skipsBySource['link'] += $linkCount;
                $skipsBySource['recurring'] += $recurringCount;

                $dayTotal = $daySkips->count();
                $totalActiveSkips += $dayTotal;

                $selfServiceDayCount = $selfCount + $recurringCount;

                $dailySeries[] = [
                    'date' => $dateStr,
                    'manual_skips' => $hrCount,
                    'self_service_skips' => $selfServiceDayCount,
                    'total_skips' => $dayTotal,
                ];

                if ($hrCount >= 20) {
                    $daysWith20PlusHrSkips++;
                }
            }

            $current->addDay();
        }

        $selfServiceSkipsTotal = $skipsBySource['self'] + $skipsBySource['recurring'];
        $selfServicePct = $totalActiveSkips > 0
            ? round(($selfServiceSkipsTotal / $totalActiveSkips) * 100, 1)
            : null;

        // Employees summary
        $totalActiveEmployees = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->count();

        $loginsCreated = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->count();

        $loggedInAtLeastOnce = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->whereHas('user', function ($q) {
                $q->whereNotNull('last_login_at');
            })
            ->count();

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_active_skips' => $totalActiveSkips,
            'self_service_pct' => $selfServicePct,
            'self_skips_count' => $skipsBySource['self'],
            'recurring_skips_count' => $skipsBySource['recurring'],
            'skips_by_source' => $skipsBySource,
            'days_with_20_plus_hr_skips' => $daysWith20PlusHrSkips,
            'employees' => [
                'total_active' => $totalActiveEmployees,
                'logins_created' => $loginsCreated,
                'logged_in_at_least_once' => $loggedInAtLeastOnce,
            ],
            'daily_series' => $dailySeries,
        ];
    }
}
