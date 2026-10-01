<?php

namespace App\Actions\Meal;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
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
        int $quantity,
        string $type = 'guest',
        ?string $reason = null
    ): MealAdjustment {
        if ($quantity <= 0) {
            throw new MealRuleViolation('Quantity must be a positive integer.', MealRuleReason::INVALID_QUANTITY);
        }

        MealGuard::assertCompanyOwns($company->id, $createdBy->company_id, 'User does not belong to this company.');
        MealGuard::assertEditable($company, $date, ['meal_day', 'locked', 'cutoff'], 'record extra meal');

        return MealAdjustment::create([
            'company_id' => $company->id,
            'date' => $date,
            'quantity' => $quantity,
            'type' => $type,
            'reason' => $reason,
            'created_by' => $createdBy->id,
        ]);
    }
}
