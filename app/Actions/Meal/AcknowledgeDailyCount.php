<?php

namespace App\Actions\Meal;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\MealCount;
use App\Models\User;

class AcknowledgeDailyCount
{
    public function execute(Company $company, string $date, User $reviewer): MealCount
    {
        // 1. Cross-company & Role Guard
        if ($reviewer->company_id !== $company->id) {
            throw new MealRuleViolation('User does not belong to this company.', MealRuleReason::CROSS_COMPANY);
        }

        if ($reviewer->role !== 'company_admin') {
            throw new MealRuleViolation('Only company admins can acknowledge daily counts.', MealRuleReason::FORBIDDEN_ROLE);
        }

        $activeAssignment = $company->activeAssignment;
        if (! $activeAssignment) {
            throw new MealRuleViolation("Company {$company->id} has no active Tiffin Service assignment.", MealRuleReason::INVALID_SOURCE);
        }

        // 2. Fetch or create draft MealCount
        $mealCount = MealCount::firstOrCreate(
            [
                'company_id' => $company->id,
                'date' => $date,
            ],
            [
                'tiffin_service_id' => $activeAssignment?->tiffin_service_id,
                'base_eligible_count' => 0,
                'skip_count' => 0,
                'extra_count' => 0,
                'final_expected_count' => 0,
                'breakdown' => [],
                'status' => 'draft',
            ]
        );

        $mealCount->update([
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        return $mealCount;
    }
}
