<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\User;
use App\Services\MealCalendar;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BulkRecordSkip
{
    public function execute(
        Company $company,
        array $employeeIds,
        array $dates,
        string $source = 'hr',
        ?string $reason = null,
        ?User $createdBy = null
    ): array {
        $uniqueDates = array_values(array_unique($dates));

        if (count($uniqueDates) > 31) {
            throw new InvalidArgumentException('Bulk skip date range cannot exceed 31 days.');
        }

        $employees = Employee::where('company_id', $company->id)
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        $recordSkipAction = new RecordSkip;
        $results = [];
        $createdCount = 0;
        $alreadySkippedCount = 0;
        $nonMealDayCount = 0;
        $rejectedCount = 0;

        DB::transaction(function () use (
            $company,
            $employeeIds,
            $uniqueDates,
            $employees,
            $source,
            $reason,
            $createdBy,
            $recordSkipAction,
            &$results,
            &$createdCount,
            &$alreadySkippedCount,
            &$nonMealDayCount,
            &$rejectedCount
        ) {
            foreach ($employeeIds as $employeeId) {
                $employee = $employees->get($employeeId);
                if (! $employee) {
                    foreach ($uniqueDates as $date) {
                        $rejectedCount++;
                        $results[] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'status' => 'rejected',
                            'reason' => 'Employee not found or does not belong to this company.',
                        ];
                    }

                    continue;
                }

                foreach ($uniqueDates as $date) {
                    if (! MealCalendar::isMealDay($company, $date)) {
                        $nonMealDayCount++;
                        $results[] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'status' => 'skipped_non_meal_day',
                            'reason' => 'Non-working meal day.',
                        ];

                        continue;
                    }

                    // Check if already active skip exists
                    $existingSkip = Skip::where('employee_id', $employeeId)
                        ->where('date', $date)
                        ->whereNull('cancelled_at')
                        ->first();

                    if ($existingSkip) {
                        $alreadySkippedCount++;
                        $results[] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'status' => 'already_skipped',
                            'reason' => 'Skip record already exists.',
                        ];

                        continue;
                    }

                    try {
                        $recordSkipAction->execute($company, $employee, $date, $source, $reason, $createdBy);
                        $createdCount++;
                        $results[] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'status' => 'created',
                            'reason' => null,
                        ];
                    } catch (Exception $e) {
                        $rejectedCount++;
                        $results[] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'status' => 'rejected',
                            'reason' => $e->getMessage(),
                        ];
                    }
                }
            }
        });

        return [
            'total_processed' => count($employeeIds) * count($uniqueDates),
            'created_count' => $createdCount,
            'already_skipped_count' => $alreadySkippedCount,
            'non_meal_day_count' => $nonMealDayCount,
            'rejected_count' => $rejectedCount,
            'results' => $results,
        ];
    }
}
