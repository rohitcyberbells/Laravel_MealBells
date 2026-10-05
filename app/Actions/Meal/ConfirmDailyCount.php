<?php

namespace App\Actions\Meal;

use App\Events\DailyCountConfirmed;
use App\Models\Company;
use App\Models\MealCount;
use App\Models\User;
use App\Services\MealCalendar;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ConfirmDailyCount
{
    public function execute(
        Company $company,
        string $date,
        ?User $confirmedBy = null,
        bool $isAutoConfirmed = false
    ): MealCount {
        // Meal Calendar Check
        if (! MealCalendar::isMealDay($company, $date)) {
            throw new Exception("Cannot confirm daily count: Date {$date} is not a working meal day for this company.");
        }

        // Active Tiffin assignment check
        $activeAssignment = $company->activeAssignment;
        if (! $activeAssignment) {
            throw new Exception('Company is not assigned to any Tiffin Service.');
        }

        // Idempotency check: If already locked, return existing snapshot
        $existing = MealCount::where('company_id', $company->id)
            ->where('date', $date)
            ->whereNotNull('locked_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        // Calculate expected numbers & breakdown using Pure Action
        $calculator = new CalculateExpectedMeals;
        $calculatedData = $calculator->execute($company, $date);

        $status = $isAutoConfirmed ? 'auto_confirmed' : 'confirmed';

        // Lock & Save Immutable Snapshot inside DB transaction with unique constraint race handling
        try {
            return DB::transaction(function () use ($company, $date, $activeAssignment, $calculatedData, $status, $confirmedBy) {
                $snapshot = MealCount::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'date' => $date,
                    ],
                    [
                        'tiffin_service_id' => $activeAssignment->tiffin_service_id,
                        'base_eligible_count' => $calculatedData['base_eligible_count'],
                        'skip_count' => $calculatedData['skip_count'],
                        'extra_count' => $calculatedData['extra_count'],
                        'final_expected_count' => $calculatedData['final_expected_count'],
                        'breakdown' => $calculatedData['breakdown'],
                        'status' => $status,
                        'confirmed_by' => $confirmedBy?->id,
                        'locked_at' => now(),
                    ]
                );

                DailyCountConfirmed::dispatch($snapshot);

                return $snapshot;
            });
        } catch (QueryException $e) {
            $existing = MealCount::where('company_id', $company->id)
                ->where('date', $date)
                ->first();

            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }
}
