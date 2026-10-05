<?php

namespace App\Actions\Meal;

use App\Enums\MealRuleReason;
use App\Events\PostCutoffChangeRecorded;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\MealCount;
use App\Models\MealCountChange;
use App\Models\User;
use App\Services\MealGuard;
use Illuminate\Support\Facades\DB;

class RecordPostCutoffChange
{
    public function execute(
        Company $company,
        string $date,
        int $changeQuantity,
        string $reason,
        User $requestedBy
    ): MealCountChange {
        // 1. FORBIDDEN_ROLE Check: Only company_admin role is allowed
        if ($requestedBy->role !== 'company_admin') {
            throw new MealRuleViolation('Only company admins can record post-cutoff changes.', MealRuleReason::FORBIDDEN_ROLE);
        }

        // 2. CROSS_COMPANY Check
        MealGuard::assertCompanyOwns($company->id, $requestedBy->company_id, 'User does not belong to this company.');

        return DB::transaction(function () use ($company, $date, $changeQuantity, $reason, $requestedBy) {
            // Fetch meal count snapshot with lockForUpdate inside transaction
            $mealCount = MealCount::where('company_id', $company->id)
                ->where('date', $date)
                ->lockForUpdate()
                ->first();

            // 3. COUNT_NOT_LOCKED Check
            if (! $mealCount || ! $mealCount->locked_at) {
                throw new MealRuleViolation('Cannot log post-cutoff change: Meal count snapshot is not locked yet.', MealRuleReason::COUNT_NOT_LOCKED);
            }

            // 4. INVALID_QUANTITY Check
            if ($changeQuantity === 0) {
                throw new MealRuleViolation('Change quantity cannot be zero.', MealRuleReason::INVALID_QUANTITY);
            }

            // 5. NEGATIVE_TOTAL Check
            $currentAdjustedTotal = $mealCount->adjusted_total;
            if ($currentAdjustedTotal + $changeQuantity < 0) {
                throw new MealRuleViolation('Post-cutoff change would result in a negative total meal count.', MealRuleReason::NEGATIVE_TOTAL);
            }

            // 6.  TOTAL_MEAL Check

            $change = MealCountChange::create([
                'meal_count_id' => $mealCount->id,
                'change_quantity' => $changeQuantity,
                'reason' => $reason,
                'requested_by' => $requestedBy->id,
            ]);

            PostCutoffChangeRecorded::dispatch($change);

            return $change;
        });
    }
}
