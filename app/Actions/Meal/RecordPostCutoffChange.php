<?php

namespace App\Actions\Meal;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\MealCount;
use App\Models\MealCountChange;
use App\Models\User;
use App\Services\MealGuard;

class RecordPostCutoffChange
{
    public function execute(
        Company $company,
        string $date,
        int $changeQuantity,
        string $reason,
        User $requestedBy
    ): MealCountChange {
        if ($changeQuantity === 0) {
            throw new MealRuleViolation('Change quantity cannot be zero.', MealRuleReason::INVALID_QUANTITY);
        }

        MealGuard::assertCompanyOwns($company->id, $requestedBy->company_id, 'User does not belong to this company.');

        $mealCount = MealCount::where('company_id', $company->id)
            ->where('date', $date)
            ->first();

        // Fixed reason code to COUNT_NOT_LOCKED
        if (! $mealCount || ! $mealCount->locked_at) {
            throw new MealRuleViolation('Cannot log post-cutoff change: Meal count snapshot is not locked yet.', MealRuleReason::COUNT_NOT_LOCKED);
        }

        return MealCountChange::create([
            'meal_count_id' => $mealCount->id,
            'change_quantity' => $changeQuantity,
            'reason' => $reason,
            'requested_by' => $requestedBy->id,
        ]);
    }
}
