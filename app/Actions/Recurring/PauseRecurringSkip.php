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
    public function execute(RecurringSkip $rule, ?User $updatedBy = null): RecurringSkip
    {
        $newActiveState = ! $rule->active;
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
