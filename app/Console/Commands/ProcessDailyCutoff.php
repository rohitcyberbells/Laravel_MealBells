<?php

namespace App\Console\Commands;

use App\Actions\Meal\ConfirmDailyCount;
use App\Models\Company;
use App\Models\MealCount;
use App\Services\MealCalendar;
use App\Services\MealCutoff;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessDailyCutoff extends Command
{
    protected $signature = 'mealbells:process-cutoff';

    protected $description = 'Process daily cutoff for companies and lock meal count snapshot';

    public function handle(): void
    {
        $companies = Company::with('setting', 'activeAssignment')->get();
        $action = new ConfirmDailyCount;

        foreach ($companies as $company) {
            $setting = $company->setting;
            if (! $setting || ! $company->activeAssignment) {
                continue;
            }

            $timezone = $setting->timezone ?? 'Asia/Kolkata';
            $todayDate = Carbon::today($timezone)->toDateString();

            // 1. Check if today is a working meal day for this company
            if (! MealCalendar::isMealDay($company, $todayDate)) {
                continue;
            }

            // 2. Check if today's snapshot is already locked (Idempotency check)
            $alreadyLocked = MealCount::where('company_id', $company->id)
                ->where('date', $todayDate)
                ->whereNotNull('locked_at')
                ->exists();

            if ($alreadyLocked) {
                continue;
            }

            // 3. Trigger cutoff lock using centralized MealCutoff helper
            if (MealCutoff::hasCutoffPassed($company, $todayDate)) {
                try {
                    $action->execute($company, $todayDate, null, true);
                    $this->info("Cutoff snapshot locked for Company ID: {$company->id}");
                } catch (\Exception $e) {
                    $this->error("Failed cutoff for Company ID: {$company->id} - ".$e->getMessage());
                }
            }
        }
    }
}
