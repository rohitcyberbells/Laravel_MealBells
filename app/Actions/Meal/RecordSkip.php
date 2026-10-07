<?php

namespace App\Actions\Meal;

use App\Enums\SkipOutcome;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\User;
use App\Services\MealGuard;
use App\Support\SkipResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RecordSkip
{
    public function execute(
        Company $company,
        Employee $employee,
        string $date,
        string $source = 'hr',
        ?string $reason = null,
        ?User $createdBy = null,
        ?string $externalRef = null
    ): SkipResult {
        // Strict Order of Guards
        MealGuard::assertCompanyOwns($company->id, $employee->company_id, 'Employee does not belong to this company.');
        MealGuard::assertEmployeeEligible($employee);
        MealGuard::assertValidSource($source);
        MealGuard::assertEditable($company, $date, ['meal_day', 'past', 'advance', 'locked', 'cutoff'], 'record skip');

        return DB::transaction(function () use ($company, $employee, $date, $source, $reason, $createdBy, $externalRef) {
            $existingSkip = Skip::where('employee_id', $employee->id)
                ->where('date', $date)
                ->first();

            if ($existingSkip) {
                // Case 1: Active skip already exists (cancelled_at is null) -> First Source Wins!
                if ($existingSkip->cancelled_at === null) {
                    return new SkipResult($existingSkip, SkipOutcome::ALREADY_SKIPPED);
                }

                // Case 2: Cancelled skip exists (cancelled_at is not null)
                $manualSources = config('mealbells.manual_skip_sources', ['hr', 'self']);
                if (in_array($source, $manualSources)) {
                    // Manual source (hr, self) reactivates cancelled skip
                    $existingSkip->update([
                        'company_id' => $company->id,
                        'source' => $source,
                        'reason' => $reason,
                        'created_by' => $createdBy?->id,
                        'external_ref' => $externalRef,
                        'cancelled_at' => null,
                        'cancelled_by' => null,
                    ]);

                    return new SkipResult($existingSkip, SkipOutcome::REACTIVATED);
                }

                // An automated source may restore only a cancellation it made
                // itself, on the very same record. Both halves matter:
                //
                //   cancelled_source non-null - a person did not cancel this, so
                //     nobody's decision is being overridden. Null, which is every
                //     skip a human cancelled, stays blocked.
                //   external_ref matching - it is the same leave coming back, not
                //     a different one landing on a day someone had freed.
                //
                // Without this, a leave approved, withdrawn and approved again in
                // the HR system left the skip cancelled for good, and the meal was
                // counted while the person was away.
                $sameRecordReturning = $existingSkip->cancelled_source !== null
                    && $externalRef !== null
                    && $existingSkip->external_ref === $externalRef;

                if ($sameRecordReturning) {
                    $existingSkip->update([
                        'company_id' => $company->id,
                        'source' => $source,
                        'reason' => $reason,
                        'created_by' => $createdBy?->id,
                        'external_ref' => $externalRef,
                        'cancelled_at' => null,
                        'cancelled_by' => null,
                        'cancelled_source' => null,
                    ]);

                    return new SkipResult($existingSkip, SkipOutcome::REACTIVATED);
                }

                // Any other auto source (leave, wfh, recurring, link) cannot
                // reactivate a cancelled skip.
                return new SkipResult($existingSkip, SkipOutcome::BLOCKED_CANCELLED);
            }

            // Case 3: Create new skip (with DB race condition handling)
            try {
                $skip = Skip::create([
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'date' => $date,
                    'source' => $source,
                    'reason' => $reason,
                    'created_by' => $createdBy?->id,
                    'external_ref' => $externalRef,
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                ]);

                return new SkipResult($skip, SkipOutcome::CREATED);
            } catch (QueryException $e) {
                // Fallback for DB unique constraint race condition
                $raceSkip = Skip::where('employee_id', $employee->id)->where('date', $date)->first();
                if ($raceSkip) {
                    return new SkipResult($raceSkip, SkipOutcome::ALREADY_SKIPPED);
                }

                throw $e;
            }
        });
    }
}
