<?php

namespace App\Actions\Recurring;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\RecurringSkip;
use App\Models\User;
use App\Services\MealGuard;
use Carbon\Carbon;

class CreateRecurringSkip
{
    public function execute(
        Company $company,
        Employee $employee,
        int $weekday,
        string $startsOn,
        ?string $endsOn = null,
        ?User $createdBy = null
    ): RecurringSkip {
        MealGuard::assertCompanyOwns($company->id, $employee->company_id, 'Employee does not belong to this company.');
        MealGuard::assertEmployeeEligible($employee);

        if ($weekday < 1 || $weekday > 7) {
            throw new MealRuleViolation('Weekday must be between 1 (Monday) and 7 (Sunday).', MealRuleReason::INVALID_QUANTITY);
        }

        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
        $todayStr = Carbon::today($timezone)->toDateString();

        if ($startsOn < $todayStr) {
            throw new MealRuleViolation('starts_on cannot be in the past.', MealRuleReason::PAST_DATE);
        }

        if ($endsOn && $endsOn < $startsOn) {
            throw new MealRuleViolation('ends_on cannot be earlier than starts_on.', MealRuleReason::INVALID_QUANTITY);
        }

        // Duplicate Active Rule Check (Overlap Detection)
        $existing = RecurringSkip::where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->where('weekday', $weekday)
            ->where('active', true)
            ->where(function ($query) use ($startsOn, $endsOn) {
                $query->where(function ($sub) use ($startsOn, $endsOn) {
                    $sub->where('starts_on', '<=', $endsOn ?? '2099-12-31')
                        ->where(function ($inner) use ($startsOn) {
                            $inner->whereNull('ends_on')
                                ->orWhere('ends_on', '>=', $startsOn);
                        });
                });
            })
            ->first();

        if ($existing) {
            throw new MealRuleViolation('An active recurring skip rule already exists for this weekday.', MealRuleReason::DUPLICATE_RULE);
        }

        return RecurringSkip::create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'weekday' => $weekday,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'active' => true,
            'created_by' => $createdBy?->id,
        ]);
    }
}
