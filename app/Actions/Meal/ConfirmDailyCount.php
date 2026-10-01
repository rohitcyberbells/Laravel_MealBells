<?php

namespace App\Actions\Meal;

use App\Events\DailyCountConfirmed;
use App\Models\Company;
use App\Models\MealCount;
use App\Models\User;
use App\Services\MealCalendar;
use Exception;

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

        // Calculate expected numbers & breakdown using Pure Action
        $calculator = new CalculateExpectedMeals;
        $calculatedData = $calculator->execute($company, $date);

        $status = $isAutoConfirmed ? 'auto_confirmed' : 'confirmed';

        // Lock & Save Immutable Snapshot
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
    }
}
