<?php

namespace App\Services;

use App\Models\Company;
use Carbon\Carbon;

class MealCalendar
{
    public static function isMealDay(Company $company, string|Carbon $date): bool
    {
        $carbonDate = is_string($date) ? Carbon::parse($date) : $date->copy();
        $dayOfWeekIso = $carbonDate->dayOfWeekIso; // 1 (Monday) to 7 (Sunday)

        $setting = $company->setting;
        $mealDays = $setting?->meal_days ?? [1, 2, 3, 4, 5];

        return in_array($dayOfWeekIso, $mealDays);
    }
}
