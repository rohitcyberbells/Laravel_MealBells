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
        //
        // Through the per-request memo: this is asked the same question many
        // times in one request, and each ask used to be its own query.
        $calendarOverride = app(MealCalendarCache::class)->override($company, $dateStr);

        if ($calendarOverride) {
            return $calendarOverride->type === 'working_day';
        }

        // 2. Default Company Setting Meal Days Fallback
        $dayOfWeekIso = $carbonDate->dayOfWeekIso; // 1 (Monday) to 7 (Sunday)

        $setting = $company->setting;
        $mealDays = $setting?->meal_days ?? [1, 2, 3, 4, 5];

        return in_array($dayOfWeekIso, $mealDays);
    }

    /**
     * Warm the memo for a span of days in one query.
     *
     * For the pages that walk a range - the forecast strip, the employee's week,
     * the vendor's day - where the alternative is one query per day.
     */
    public static function preload(Company $company, string $from, string $to): void
    {
        app(MealCalendarCache::class)->preload($company, $from, $to);
    }

    /**
     * The override row for one date, from the memo.
     *
     * Exposed because a page that shows the holiday's note needs the row
     * itself, and asking for it separately would re-query what preload() has
     * already fetched.
     */
    public static function override(Company $company, string $date): ?CompanyCalendarDay
    {
        return app(MealCalendarCache::class)->override($company, $date);
    }

    /**
     * Called when this request is the one changing the calendar.
     */
    public static function forget(int $companyId): void
    {
        app(MealCalendarCache::class)->forget($companyId);
    }
}
