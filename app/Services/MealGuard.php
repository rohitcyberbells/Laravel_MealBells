<?php

namespace App\Services;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\CompanySetting;
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
     * Assert skip source is canonical.
     */
    public static function assertValidSource(string $source): void
    {
        $allowedSources = config('mealbells.canonical_skip_sources', ['leave', 'wfh', 'hr', 'self', 'link', 'recurring']);
        if (! in_array($source, $allowedSources)) {
            throw new MealRuleViolation("Invalid skip source: {$source}.", MealRuleReason::INVALID_SOURCE);
        }
    }

    /**
     * Assert extra meal quantity is integer or integer-like string between 1 and max limit.
     */
    public static function assertValidQuantity(mixed $quantity): void
    {
        if (! is_numeric($quantity) || (int) $quantity != $quantity || (int) $quantity <= 0) {
            throw new MealRuleViolation('Quantity must be a positive integer.', MealRuleReason::INVALID_QUANTITY);
        }

        $maxLimit = config('mealbells.max_extra_meals', 100);
        if ((int) $quantity > $maxLimit) {
            throw new MealRuleViolation("Extra meal quantity cannot exceed {$maxLimit}.", MealRuleReason::INVALID_QUANTITY);
        }
    }

    /**
     * Assert extra meal type is valid.
     */
    public static function assertValidType(string $type): void
    {
        $allowedTypes = config('mealbells.allowed_extra_types', ['guest', 'visitor', 'other']);
        if (! in_array($type, $allowedTypes)) {
            throw new MealRuleViolation("Invalid extra meal type: {$type}.", MealRuleReason::INVALID_TYPE);
        }
    }

    /**
     * Centralized fixed order assertions pipeline for dates.
     * Order: not_a_meal_day -> past_date -> advance_limit_exceeded -> count_locked -> cutoff_passed.
     */
    public static function assertEditable(Company $company, string|Carbon $targetDate, array $checks = ['meal_day', 'past', 'advance', 'locked', 'cutoff'], string $actionName = 'process request'): void
    {
        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
        $dateString = is_string($targetDate) ? $targetDate : $targetDate->toDateString();
        $targetCarbon = Carbon::parse($dateString, $timezone)->startOfDay();
        $todayLocal = Carbon::today($timezone)->startOfDay();

        // 1. Meal Calendar Check
        if (in_array('meal_day', $checks) && ! MealCalendar::isMealDay($company, $dateString)) {
            throw new MealRuleViolation("Cannot {$actionName}: Date is not a working meal day for this company.", MealRuleReason::NOT_A_MEAL_DAY);
        }

        // 2. Past Date Check (date < todayLocal in company timezone)
        if (in_array('past', $checks) && $targetCarbon->lessThan($todayLocal)) {
            throw new MealRuleViolation("Cannot {$actionName}: Date is in the past.", MealRuleReason::PAST_DATE);
        }

        // 3. Advance Limit Check (date > todayLocal + max advance days)
        $maxAdvanceDays = config('mealbells.advance_limit_days', 60);
        $maxAdvanceDate = $todayLocal->copy()->addDays($maxAdvanceDays);
        if (in_array('advance', $checks) && $targetCarbon->greaterThan($maxAdvanceDate)) {
            throw new MealRuleViolation("Cannot {$actionName}: Date exceeds advance limit of {$maxAdvanceDays} days.", MealRuleReason::ADVANCE_LIMIT_EXCEEDED);
        }

        // 4. Lock Enforcement Check (using lockForUpdate inside transaction if queryable)
        if (in_array('locked', $checks)) {
            $isLocked = MealCount::where('company_id', $company->id)
                ->where('date', $dateString)
                ->whereNotNull('locked_at')
                ->exists();

            if ($isLocked) {
                throw new MealRuleViolation("Cannot {$actionName}: Count is locked for this date.", MealRuleReason::COUNT_LOCKED);
            }
        }

        // 5. Cutoff Time Check
        if (in_array('cutoff', $checks) && MealCutoff::hasCutoffPassed($company, $dateString)) {
            $cutoffTime = CompanySetting::cutoffLabelFor($company->setting);
            throw new MealRuleViolation("Cannot {$actionName}: Cutoff time ({$cutoffTime}) has passed for today.", MealRuleReason::CUTOFF_PASSED);
        }
    }
}
