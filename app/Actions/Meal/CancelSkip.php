<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\Skip;
use App\Models\User;
use App\Services\MealGuard;

class CancelSkip
{
    /**
     * @param  ?string  $cancelledSource  the automated source releasing its own
     *                                    skip, or null when a person cancelled it.
     *                                    Recorded because cancelled_by is a real
     *                                    user either way - an integration acts as
     *                                    the company's admin - so it cannot tell
     *                                    the two apart, and RecordSkip needs to.
     */
    public function execute(Company $company, Skip $skip, User $cancelledBy, ?string $cancelledSource = null): Skip
    {
        MealGuard::assertCompanyOwns($company->id, $skip->company_id, 'Skip record does not belong to this company.');

        // Idempotent: If already cancelled, return as is
        if ($skip->cancelled_at !== null) {
            return $skip;
        }

        MealGuard::assertEditable($company, $skip->date, ['past', 'locked', 'cutoff'], 'cancel skip');

        $skip->update([
            'cancelled_at' => now(),
            'cancelled_by' => $cancelledBy->id,
            'cancelled_source' => $cancelledSource,
        ]);

        return $skip;
    }
}
