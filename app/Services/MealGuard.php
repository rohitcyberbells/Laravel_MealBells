<?php

namespace App\Services;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\MealCount;
use Carbon\Carbon;

class MealGuard
{
    /**
     * Loose ID comparison using (int) casting to avoid driver type mismatch bugs.
     */
    public static function assertCompanyOwns(int|string $expectedCompanyId, int|string $actualCompanyId, string $errorMessage = 'Record does not belong to this company.'): void
    {
        if ((int) $expectedCompanyId !== (int) $actualCompanyId) {
            throw new MealRuleViolation($errorMessage, MealRuleReason::CROSS_COMPANY);
        }
    }

    /**
     * Assert employee is active and meal eligible.
     */
    public static function assertEmployeeEligible(Employee $employee): void
    {
        if ($employee->status !== 'active' || ! $employee->is_meal_eligible) {
            throw new MealRuleViolation('Cannot record skip: Employee is inactive or not eligible for meals.', MealRuleReason::INACTIVE_EMPLOYEE);
        }
    }

    /**
     * Assert editable status for a date (meal day, lock status, cutoff time).
     */
    public static function assertEditable(Company $company, string|Carbon $targetDate, array $checks = ['meal_day', 'locked', 'cutoff'], string $actionName = 'process request'): void
    {
        $dateString = is_string($targetDate) ? $targetDate : $targetDate->toDateString();

        // 1. Meal Calendar Check
        if (in_array('meal_day', $checks) && ! MealCalendar::isMealDay($company, $dateString)) {
            throw new MealRuleViolation("Cannot {$actionName}: Date is not a working meal day for this company.", MealRuleReason::NOT_A_MEAL_DAY);
        }

        // 2. Lock Enforcement Check
        if (in_array('locked', $checks)) {
            $isLocked = MealCount::where('company_id', $company->id)
                ->where('date', $dateString)
                ->whereNotNull('locked_at')
                ->exists();

            if ($isLocked) {
                throw new MealRuleViolation("Cannot {$actionName}: Count is locked for this date.", MealRuleReason::COUNT_LOCKED);
            }
        }

        // 3. Cutoff Time Check
        if (in_array('cutoff', $checks) && MealCutoff::hasCutoffPassed($company, $dateString)) {
            $cutoffTime = $company->setting?->cutoff_time ?? '10:30:00';
            throw new MealRuleViolation("Cannot {$actionName}: Cutoff time ({$cutoffTime}) has passed for today.", MealRuleReason::CUTOFF_PASSED);
        }
    }
}
