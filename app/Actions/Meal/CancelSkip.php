<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\Skip;
use App\Models\User;
use App\Services\MealGuard;

class CancelSkip
{
    public function execute(Company $company, Skip $skip, User $cancelledBy): Skip
    {
        MealGuard::assertCompanyOwns($company->id, $skip->company_id, 'Skip record does not belong to this company.');

        // Idempotent: If already cancelled, return as is
        if ($skip->cancelled_at !== null) {
            return $skip;
        }

        MealGuard::assertEditable($company, $skip->date, ['locked', 'cutoff'], 'cancel skip');

        $skip->update([
            'cancelled_at' => now(),
            'cancelled_by' => $cancelledBy->id,
        ]);

        return $skip;
    }
}
