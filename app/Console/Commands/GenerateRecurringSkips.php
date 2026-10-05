<?php

namespace App\Console\Commands;

use App\Actions\Meal\RecordSkip;
use App\Models\Company;
use App\Models\MealCount;
use App\Models\RecurringSkip;
use App\Services\MealCalendar;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GenerateRecurringSkips extends Command
{
    protected $signature = 'mealbells:generate-recurring-skips';

    protected $description = 'Generate recurring skips for companies up to horizon days ahead';

    public function handle(): void
    {
        Cache::put('scheduler_last_run', now()->timestamp);

        $companies = Company::all();
        $recordSkip = new RecordSkip;

        foreach ($companies as $company) {
            $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
            $horizonDays = (int) config('mealbells.recurring_horizon_days', 7);

            $today = Carbon::today($timezone);

            $rules = RecurringSkip::where('company_id', $company->id)
                ->where('active', true)
                ->with('employee')
                ->get();

            foreach ($rules as $rule) {
                $employee = $rule->employee;
                if (! $employee || $employee->status !== 'active' || ! $employee->is_meal_eligible) {
                    continue;
                }

                for ($day = 0; $day < $horizonDays; $day++) {
                    $dateCarbon = $today->copy()->addDays($day);
                    $dateStr = $dateCarbon->toDateString();

                    // Check weekday match
                    if ($dateCarbon->dayOfWeekIso !== $rule->weekday) {
                        continue;
                    }

                    // Check date within starts_on and ends_on
                    if ($dateStr < $rule->starts_on) {
                        continue;
                    }
                    if ($rule->ends_on && $dateStr > $rule->ends_on) {
                        continue;
                    }

                    // Check working meal day (incorporates calendar overrides)
                    if (! MealCalendar::isMealDay($company, $dateStr)) {
                        continue;
                    }

                    // Check locked date
                    $isLocked = MealCount::where('company_id', $company->id)
                        ->where('date', $dateStr)
                        ->whereNotNull('locked_at')
                        ->exists();

                    if ($isLocked) {
                        continue;
                    }

                    try {
                        $recordSkip->execute(
                            $company,
                            $employee,
                            $dateStr,
                            'recurring',
                            'Recurring skip rule',
                            $rule->createdBy
                        );
                    } catch (\Exception $e) {
                        // Idempotent execution
                    }
                }
            }
        }
    }
}
