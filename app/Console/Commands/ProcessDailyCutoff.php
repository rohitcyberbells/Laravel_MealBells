<?php

namespace App\Console\Commands;

use App\Actions\Meal\ConfirmDailyCount;
use App\Actions\Meal\EscalateUnreviewedAnomaly;
use App\Actions\Meal\PrepareDailyCountSummary;
use App\Models\Company;
use App\Models\User;
use App\Notifications\SuperAdminCutoffErrorNotification;
use App\Services\MealCalendar;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessDailyCutoff extends Command
{
    protected $signature = 'mealbells:process-cutoff';

    protected $description = 'Process daily summary, escalation, and cutoff auto-confirm for all companies';

    public function handle(): void
    {
        Cache::put('scheduler_last_run', now()->timestamp);

        $companies = Company::with('setting', 'activeAssignment')->get();

        foreach ($companies as $company) {
            try {
                $setting = $company->setting;
                if (! $setting || ! $company->activeAssignment) {
                    continue;
                }

                $timezone = $setting->timezone ?? 'Asia/Kolkata';
                $todayDate = Carbon::today($timezone)->toDateString();

                // Check working meal day
                if (! MealCalendar::isMealDay($company, $todayDate)) {
                    continue;
                }

                $cutoffTimeStr = $setting->cutoff_time ?? '11:00';
                $parts = explode(':', $cutoffTimeStr);
                $hour = (int) ($parts[0] ?? 11);
                $minute = (int) ($parts[1] ?? 0);

                $cutoffDateTime = Carbon::createFromFormat('Y-m-d', $todayDate, $timezone)->setTime($hour, $minute, 0);
                $summaryDateTime = $cutoffDateTime->copy()->subMinutes(30);
                $escalateDateTime = $cutoffDateTime->copy()->subMinutes(15);
                $now = Carbon::now($timezone);

                // Stage 1: Summary (now >= cutoff - 30 min)
                if ($now->gte($summaryDateTime)) {
                    try {
                        (new PrepareDailyCountSummary)->execute($company, $todayDate);
                    } catch (\Throwable $e) {
                        $this->handleCompanyError($company, $todayDate, 'Summary', $e);
                    }
                }

                // Stage 2: Escalation (now >= cutoff - 15 min)
                if ($now->gte($escalateDateTime)) {
                    try {
                        (new EscalateUnreviewedAnomaly)->execute($company, $todayDate);
                    } catch (\Throwable $e) {
                        $this->handleCompanyError($company, $todayDate, 'Escalation', $e);
                    }
                }

                // Stage 3: Auto-confirm lock (now >= cutoff)
                if ($now->gte($cutoffDateTime)) {
                    try {
                        (new ConfirmDailyCount)->execute($company, $todayDate, null, true);
                        $this->info("Cutoff snapshot locked for Company ID: {$company->id}");
                    } catch (\Throwable $e) {
                        $this->handleCompanyError($company, $todayDate, 'ConfirmDailyCount', $e);
                    }
                }
            } catch (\Throwable $e) {
                $todayDate = isset($timezone) ? Carbon::today($timezone)->toDateString() : date('Y-m-d');
                $this->handleCompanyError($company, $todayDate, 'ProcessCutoff', $e);
            }
        }
    }

    protected function handleCompanyError(Company $company, string $date, string $stage, \Throwable $e): void
    {
        $errorMsg = "[{$stage}] ".$e->getMessage();
        $this->error("Error processing Company ID {$company->id}: {$errorMsg}");
        Log::error("Cutoff processing error for company {$company->id} on {$date}: {$errorMsg}", [
            'exception' => $e,
        ]);

        $cacheKey = "cutoff_err_sent_{$company->id}_{$date}";
        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, true, now()->addDay());
            $superAdmins = User::where('role', 'super_admin')->get();
            foreach ($superAdmins as $admin) {
                $admin->notify(new SuperAdminCutoffErrorNotification($company, $date, $errorMsg));
            }
        }
    }
}
