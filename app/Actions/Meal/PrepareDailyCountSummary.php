<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\MealCount;
use App\Models\User;
use App\Notifications\DailyCountSummaryNotification;
use Exception;

class PrepareDailyCountSummary
{
    public function execute(Company $company, string $date): MealCount
    {
        // 1. Idempotency Check
        $existing = MealCount::where('company_id', $company->id)
            ->where('date', $date)
            ->first();

        if ($existing && $existing->summary_sent_at !== null) {
            return $existing;
        }

        $activeAssignment = $company->activeAssignment;
        if (! $activeAssignment) {
            throw new Exception("Company {$company->id} has no active Tiffin Service assignment.");
        }

        // 2. Calculate numbers & detect anomalies
        $calculator = new CalculateExpectedMeals;
        $calculatedData = $calculator->execute($company, $date);

        $anomalyDetector = new DetectCountAnomalies;
        $anomalyFlags = $anomalyDetector->execute($company, $date, $calculatedData);

        // 3. Upsert Draft MealCount record
        $mealCount = MealCount::updateOrCreate(
            [
                'company_id' => $company->id,
                'date' => $date,
            ],
            [
                'tiffin_service_id' => $activeAssignment->tiffin_service_id,
                'base_eligible_count' => $calculatedData['base_eligible_count'],
                'skip_count' => $calculatedData['skip_count'],
                'extra_count' => $calculatedData['extra_count'],
                'final_expected_count' => $calculatedData['final_expected_count'],
                'breakdown' => $calculatedData['breakdown'],
                'anomaly_flags' => $anomalyFlags,
                'status' => $existing && $existing->status ? $existing->status : 'draft',
            ]
        );

        // 4. Send Notification (Primary Admin if set, otherwise all Company Admins)
        $primaryAdminId = $company->setting?->primary_admin_id;
        $recipients = collect();

        if ($primaryAdminId) {
            $primaryAdmin = User::find($primaryAdminId);
            if ($primaryAdmin && $primaryAdmin->company_id === $company->id && $primaryAdmin->role === 'company_admin') {
                $recipients->push($primaryAdmin);
            }
        }

        if ($recipients->isEmpty()) {
            $recipients = User::where('company_id', $company->id)
                ->where('role', 'company_admin')
                ->get();
        }

        $notification = new DailyCountSummaryNotification(
            $company,
            $date,
            $calculatedData['final_expected_count'],
            $anomalyFlags
        );

        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }

        $mealCount->update(['summary_sent_at' => now()]);

        return $mealCount;
    }
}
