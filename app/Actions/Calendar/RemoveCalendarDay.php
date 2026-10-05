<?php

namespace App\Actions\Calendar;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\CompanyCalendarDay;
use App\Models\MealCount;
use Carbon\Carbon;

class RemoveCalendarDay
{
    public function execute(Company $company, string $date): void
    {
        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
        $todayStr = Carbon::today($timezone)->toDateString();

        if ($date < $todayStr) {
            throw new MealRuleViolation('Cannot modify past calendar dates.', MealRuleReason::PAST_DATE);
        }

        $isLocked = MealCount::where('company_id', $company->id)
            ->where('date', $date)
            ->whereNotNull('locked_at')
            ->exists();

        if ($isLocked) {
            throw new MealRuleViolation('Cannot modify calendar for locked date.', MealRuleReason::COUNT_LOCKED);
        }

        CompanyCalendarDay::where('company_id', $company->id)
            ->where('date', $date)
            ->delete();
    }
}
