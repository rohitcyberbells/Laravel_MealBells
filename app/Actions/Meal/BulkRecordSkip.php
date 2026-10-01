<?php

namespace App\Actions\Meal;

use App\Enums\SkipOutcome;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\MealCalendar;
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
        $reactivatedCount = 0;
        $alreadySkippedCount = 0;
        $blockedCancelledCount = 0;
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
            &$reactivatedCount,
            &$alreadySkippedCount,
            &$blockedCancelledCount,
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
                            'reason_code' => 'cross_company',
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
                            'reason_code' => 'not_a_meal_day',
                            'reason' => 'Non-working meal day.',
                        ];

                        continue;
                    }

                    try {
                        $skipResult = $recordSkipAction->execute($company, $employee, $date, $source, $reason, $createdBy);

                        switch ($skipResult->outcome) {
                            case SkipOutcome::CREATED:
                                $createdCount++;
                                $status = 'created';
                                break;
                            case SkipOutcome::REACTIVATED:
                                $reactivatedCount++;
                                $status = 'reactivated';
                                break;
                            case SkipOutcome::ALREADY_SKIPPED:
                                $alreadySkippedCount++;
                                $status = 'already_skipped';
                                break;
                            case SkipOutcome::BLOCKED_CANCELLED:
                                $blockedCancelledCount++;
                                $status = 'blocked_cancelled';
                                break;
                        }

                        $results[] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'status' => $status,
                            'reason_code' => null,
                            'reason' => null,
                        ];
                    } catch (MealRuleViolation $e) {
                        $rejectedCount++;
                        $results[] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'status' => 'rejected',
                            'reason_code' => $e->getReasonCodeString(),
                            'reason' => $e->getMessage(),
                        ];
                    } catch (\Exception $e) {
                        $rejectedCount++;
                        $results[] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'status' => 'rejected',
                            'reason_code' => 'unknown_error',
                            'reason' => $e->getMessage(),
                        ];
                    }
                }
            }
        });

        return [
            'total_processed' => count($employeeIds) * count($uniqueDates),
            'created_count' => $createdCount,
            'reactivated_count' => $reactivatedCount,
            'already_skipped_count' => $alreadySkippedCount,
            'blocked_cancelled_count' => $blockedCancelledCount,
            'non_meal_day_count' => $nonMealDayCount,
            'rejected_count' => $rejectedCount,
            'results' => $results,
        ];
    }
}
