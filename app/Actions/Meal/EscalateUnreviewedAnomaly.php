<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\MealCount;
use App\Models\User;
use App\Notifications\AnomalyEscalationNotification;

class EscalateUnreviewedAnomaly
{
    public function execute(Company $company, string $date): ?MealCount
    {
        $mealCount = MealCount::where('company_id', $company->id)
            ->where('date', $date)
            ->first();

        if (! $mealCount) {
            return null;
        }

        // Must have anomaly flags, not reviewed, and not already escalated
        $hasAnomalies = ! empty($mealCount->anomaly_flags);
        $isUnreviewed = $mealCount->reviewed_at === null;
        $notEscalated = $mealCount->escalation_sent_at === null;

        if ($hasAnomalies && $isUnreviewed && $notEscalated) {
            $backupAdminId = $company->setting?->backup_admin_id;
            if ($backupAdminId) {
                $backupAdmin = User::find($backupAdminId);
                if ($backupAdmin && $backupAdmin->company_id === $company->id && $backupAdmin->role === 'company_admin') {
                    $backupAdmin->notify(new AnomalyEscalationNotification(
                        $company,
                        $date,
                        $mealCount->anomaly_flags
                    ));

                    $mealCount->update(['escalation_sent_at' => now()]);
                }
            }
        }

        return $mealCount;
    }
}
