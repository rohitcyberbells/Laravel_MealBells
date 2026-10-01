<?php

namespace App\Services;

use App\Models\Company;
use Carbon\Carbon;

class MealCutoff
{
    /**
     * Check if cutoff time has passed for a given target date in company's timezone.
     * Target date can be string 'YYYY-MM-DD' or Carbon instance.
     */
    public static function hasCutoffPassed(Company $company, string|Carbon $targetDate): bool
    {
        $setting = $company->setting;
        if (! $setting || ! $setting->cutoff_time) {
            return false;
        }

        $timezone = $setting->timezone ?? 'Asia/Kolkata';
        $todayLocal = Carbon::today($timezone)->toDateString();
        $targetDateString = is_string($targetDate) ? $targetDate : $targetDate->toDateString();

        // Cutoff guard only triggers if target date is today in company timezone
        if ($targetDateString !== $todayLocal) {
            return false;
        }

        $nowLocal = Carbon::now($timezone);
        // Robust parsing supports both '10:30:00' and '10:30'
        $cutoffDateTime = Carbon::parse($targetDateString.' '.$setting->cutoff_time, $timezone);

        return $nowLocal->greaterThanOrEqualTo($cutoffDateTime);
    }
}
