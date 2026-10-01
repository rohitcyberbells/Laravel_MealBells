<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\MealAdjustment;
use App\Models\User;
use App\Services\MealGuard;

class CancelExtraMeal
{
    public function execute(Company $company, MealAdjustment $adjustment, User $cancelledBy): MealAdjustment
    {
        MealGuard::assertCompanyOwns($company->id, $adjustment->company_id, 'Meal adjustment record does not belong to this company.');

        // Idempotent: If already cancelled, return as is
        if ($adjustment->cancelled_at !== null) {
            return $adjustment;
        }

        MealGuard::assertEditable($company, $adjustment->date, ['locked', 'cutoff'], 'cancel extra meal');

        $adjustment->update([
            'cancelled_at' => now(),
            'cancelled_by' => $cancelledBy->id,
        ]);

        return $adjustment;
    }
}
