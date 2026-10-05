<?php

namespace App\Actions\Recurring;

use App\Actions\Meal\CancelSkip;
use App\Models\MealCount;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\User;
use Carbon\Carbon;

class DeleteRecurringSkip
{
    public function execute(RecurringSkip $rule, ?User $deletedBy = null): void
    {
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
            $isLocked = MealCount::where('company_id', $company->id)
                ->where('date', $skip->date)
                ->whereNotNull('locked_at')
                ->exists();

            if (! $isLocked) {
                $cancelAction->execute($company, $skip, $deletedBy ?? $rule->employee->user ?? User::first());
            }
        }

        $rule->delete();
    }
}
