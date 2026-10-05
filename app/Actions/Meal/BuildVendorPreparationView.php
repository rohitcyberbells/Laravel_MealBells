<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyTiffinAssignment;
use App\Models\MealCount;
use App\Models\TiffinService;
use App\Services\MealCalendar;
use Carbon\Carbon;
use InvalidArgumentException;

class BuildVendorPreparationView
{
    /**
     * Build preparation view data for a Tiffin Service on a specific date.
     * Enforces privacy: NO employee names, codes, emails, or skip reasons exposed.
     */
    public function execute(TiffinService $tiffinService, string $date): array
    {
        // 1. Date Range Guard Check (Past 30 days to Future 14 days)
        // Resolve timezone from first active company assignment or fallback to default_timezone.
        // Limitation: If vendor serves multiple companies across different timezones, default_timezone is used as fallback reference.
        $firstCompanySetting = CompanyTiffinAssignment::where('tiffin_service_id', $tiffinService->id)
            ->where('is_active', true)
            ->with('company.setting')
            ->first()?->company?->setting;

        $timezone = $firstCompanySetting?->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');

        $targetDate = Carbon::parse($date, $timezone)->startOfDay();
        $minDate = Carbon::today($timezone)->subDays(30)->startOfDay();
        $maxDate = Carbon::today($timezone)->addDays(14)->endOfDay();

        if ($targetDate->lessThan($minDate) || $targetDate->greaterThan($maxDate)) {
            throw new InvalidArgumentException('Date is out of allowed window (past 30 days to future 14 days).');
        }

        // 2. Fetch Companies assigned to this Tiffin Service on target date
        // Unlocked Companies: via activeOn scope
        $assignedCompanyIds = CompanyTiffinAssignment::activeOn($date)
            ->where('tiffin_service_id', $tiffinService->id)
            ->pluck('company_id')
            ->toArray();

        // Locked Companies: via meal_counts snapshot created on that date for this tiffin_service_id
        $lockedSnapshotCompanyIds = MealCount::where('tiffin_service_id', $tiffinService->id)
            ->where('date', $date)
            ->whereNotNull('locked_at')
            ->pluck('company_id')
            ->toArray();

        // Combine unique company IDs
        $allCompanyIds = array_values(array_unique(array_merge($assignedCompanyIds, $lockedSnapshotCompanyIds)));
        $companies = Company::whereIn('id', $allCompanyIds)->get();

        $companyDataList = [];
        $totalMeals = 0;
        $isOverallEstimate = false;

        $calculator = new CalculateExpectedMeals;

        foreach ($companies as $company) {
            // Check if snapshot exists and is locked
            $snapshot = MealCount::with('changes')
                ->where('company_id', $company->id)
                ->where('tiffin_service_id', $tiffinService->id)
                ->where('date', $date)
                ->whereNotNull('locked_at')
                ->first();

            if ($snapshot) {
                // Locked Date Data Sourcing (from snapshot + changes)
                $lateChanges = $snapshot->changes->map(fn ($change) => [
                    'id' => $change->id,
                    'change_quantity' => $change->change_quantity,
                    'reason' => $change->reason,
                    'created_at' => $change->created_at->format('h:i A'),
                ])->toArray();

                $adjustedTotal = $snapshot->adjusted_total;
                $totalMeals += $adjustedTotal;

                $companyDataList[] = [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'is_meal_day' => true,
                    'is_locked' => true,
                    'status' => $snapshot->status, // 'confirmed' or 'auto_confirmed'
                    'base_eligible_count' => $snapshot->base_eligible_count,
                    'skip_count' => $snapshot->skip_count, // Total skips only (no breakdown, no PII)
                    'extra_count' => $snapshot->extra_count,
                    'final_expected_count' => $snapshot->final_expected_count,
                    'adjusted_total' => $adjustedTotal,
                    'late_changes' => $lateChanges,
                ];
            } else {
                // Unlocked Date Data Sourcing (Live calculation)
                $isMealDay = MealCalendar::isMealDay($company, $date);

                if (! $isMealDay) {
                    $calendarDay = CompanyCalendarDay::where('company_id', $company->id)
                        ->where('date', $date)
                        ->first();

                    $status = ($calendarDay && $calendarDay->type === 'holiday') ? 'no_meal' : 'non_meal_day';
                    $reason = ($calendarDay && $calendarDay->type === 'holiday') ? 'holiday' : null;
                    $note = $calendarDay?->note;

                    $companyDataList[] = [
                        'company_id' => $company->id,
                        'company_name' => $company->name,
                        'is_meal_day' => false,
                        'is_locked' => false,
                        'status' => $status,
                        'reason' => $reason,
                        'note' => $note,
                        'base_eligible_count' => 0,
                        'skip_count' => 0,
                        'extra_count' => 0,
                        'final_expected_count' => 0,
                        'adjusted_total' => 0,
                        'late_changes' => [],
                    ];

                    continue;
                }

                $calculated = $calculator->execute($company, $date);
                $isOverallEstimate = true;
                $totalMeals += $calculated['final_expected_count'];

                $companyDataList[] = [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'is_meal_day' => true,
                    'is_locked' => false,
                    'status' => 'estimate',
                    'base_eligible_count' => $calculated['base_eligible_count'],
                    'skip_count' => $calculated['skip_count'],
                    'extra_count' => $calculated['extra_count'],
                    'final_expected_count' => $calculated['final_expected_count'],
                    'adjusted_total' => $calculated['final_expected_count'],
                    'late_changes' => [],
                ];
            }
        }

        return [
            'date' => $date,
            'tiffin_service' => [
                'id' => $tiffinService->id,
                'name' => $tiffinService->name,
            ],
            'summary' => [
                'total_meals' => $totalMeals,
                'is_estimate' => $isOverallEstimate,
                'total_companies' => count($companyDataList),
            ],
            'companies' => $companyDataList,
        ];
    }
}
