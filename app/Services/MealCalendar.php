<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyCalendarDay;
use Carbon\Carbon;

class MealCalendar
{
    public static function isMealDay(Company $company, string|Carbon $date): bool
    {
        $carbonDate = is_string($date) ? Carbon::parse($date) : $date->copy();
        $dateStr = $carbonDate->toDateString();

        // 1. Calendar Day Override Check (Holiday = false, Working Day = true)
        $calendarOverride = CompanyCalendarDay::where('company_id', $company->id)
            ->where('date', $dateStr)
            ->first();

        if ($calendarOverride) {
            return $calendarOverride->type === 'working_day';
        }

        // 2. Default Company Setting Meal Days Fallback
        $dayOfWeekIso = $carbonDate->dayOfWeekIso; // 1 (Monday) to 7 (Sunday)

        $setting = $company->setting;
        $mealDays = $setting?->meal_days ?? [1, 2, 3, 4, 5];

        return in_array($dayOfWeekIso, $mealDays);
    }
}
