<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\Skip;
use App\Services\MealCalendar;

class CalculateExpectedMeals
{
    /**
     * Calculate pure expected meal counts & source breakdown for a company on a given date.
     *
     * @param  string  $date  (Y-m-d)
     * @return array{
     *     date: string,
     *     base_eligible_count: int,
     *     skip_count: int,
     *     extra_count: int,
     *     final_expected_count: int,
     *     breakdown: array<string, int>
     * }
     */
    public function execute(Company $company, string $date): array
    {
        $isMealDay = MealCalendar::isMealDay($company, $date);

        if (! $isMealDay) {
            return [
                'date' => $date,
                'is_meal_day' => false,
                'base_eligible_count' => 0,
                'skip_count' => 0,
                'extra_count' => 0,
                'final_expected_count' => 0,
                'breakdown' => [
                    'leave' => 0,
                    'wfh' => 0,
                    'hr' => 0,
                    'self' => 0,
                    'link' => 0,
                    'recurring' => 0,
                ],
            ];
        }

        // 1. Base Eligible Employees (active & is_meal_eligible = true)
        $baseCount = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('is_meal_eligible', true)
            ->count();

        // 2. Fetch Active (non-cancelled) Skips & Calculate Source-wise Breakdown
        $skips = Skip::where('company_id', $company->id)
            ->where('date', $date)
            ->whereNull('cancelled_at')
            ->get();

        $skipCount = $skips->count();

        $breakdown = [
            'leave' => $skips->where('source', 'leave')->count(),
            'wfh' => $skips->where('source', 'wfh')->count(),
            'hr' => $skips->where('source', 'hr')->count(),
            'self' => $skips->where('source', 'self')->count(),
            'link' => $skips->where('source', 'link')->count(),
            'recurring' => $skips->where('source', 'recurring')->count(),
        ];

        // 3. Extra Guest Meals (non-cancelled)
        $extraCount = (int) MealAdjustment::where('company_id', $company->id)
            ->where('date', $date)
            ->whereNull('cancelled_at')
            ->sum('quantity');

        // 4. Final Expected Formula (Ensure non-negative)
        $finalExpectedCount = max(0, $baseCount + $extraCount - $skipCount);

        return [
            'date' => $date,
            'is_meal_day' => true,
            'base_eligible_count' => $baseCount,
            'skip_count' => $skipCount,
            'extra_count' => $extraCount,
            'final_expected_count' => $finalExpectedCount,
            'breakdown' => $breakdown,
        ];
    }
}
