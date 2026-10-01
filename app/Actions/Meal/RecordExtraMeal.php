<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\MealAdjustment;
use App\Models\User;
use App\Services\MealGuard;

class RecordExtraMeal
{
    public function execute(
        Company $company,
        User $createdBy,
        string $date,
        mixed $quantity,
        string $type = 'guest',
        ?string $reason = null
    ): MealAdjustment {
        // Strict Order of Guards
        MealGuard::assertCompanyOwns($company->id, $createdBy->company_id, 'User does not belong to this company.');
        MealGuard::assertEditable($company, $date, ['meal_day', 'past', 'advance', 'locked', 'cutoff'], 'record extra meal');
        MealGuard::assertValidQuantity($quantity);
        MealGuard::assertValidType($type);

        return MealAdjustment::create([
            'company_id' => $company->id,
            'date' => $date,
            'quantity' => (int) $quantity,
            'type' => $type,
            'reason' => $reason,
            'created_by' => $createdBy->id,
        ]);
    }
}
