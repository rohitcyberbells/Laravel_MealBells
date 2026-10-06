<?php

namespace App\Actions\Recurring;

use App\Actions\Meal\CancelSkip;
use App\Models\MealCount;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\User;
use Carbon\Carbon;

class PauseRecurringSkip
{
    /**
     * Set a rule's active state, or toggle it when $active is not given.
     *
     * Callers acting on a user's request should always pass $active explicitly:
     * toggling makes the operation depend on current state, so a repeated or
     * double-submitted request flips the rule back on and silently resumes
     * skipping that person's meals.
     */
    public function execute(RecurringSkip $rule, ?User $updatedBy = null, ?bool $active = null): RecurringSkip
    {
        $newActiveState = $active ?? ! $rule->active;

        if ($rule->active === $newActiveState) {
            return $rule;
        }

        $rule->update(['active' => $newActiveState]);

        // If rule is paused, cancel future unlocked recurring skips for this employee
        if (! $newActiveState) {
            $company = $rule->company;
            $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
            $todayStr = Carbon::today($timezone)->toDateString();

            $futureSkips = Skip::where('company_id', $company->id)
                ->where('employee_id', $rule->employee_id)
                ->where('date', '>', $todayStr)
                ->where('source', 'recurring')
                ->whereNull('cancelled_at')
                ->get();

            $cancelAction = new CancelSkip;
            foreach ($futureSkips as $skip) {
                // Check if date is locked
                $isLocked = MealCount::where('company_id', $company->id)
                    ->where('date', $skip->date)
                    ->whereNotNull('locked_at')
                    ->exists();

                if (! $isLocked) {
                    $canceller = $updatedBy
                        ?? $rule->createdBy
                        ?? $rule->employee?->user
                        ?? User::where('company_id', $company->id)->first();

                    if ($canceller) {
                        $cancelAction->execute($company, $skip, $canceller);
                    }
                }
            }
        }

        return $rule;
    }
}
