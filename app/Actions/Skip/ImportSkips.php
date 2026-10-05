<?php

namespace App\Actions\Skip;

use App\Actions\Meal\RecordSkip;
use App\Enums\SkipOutcome;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class ImportSkips
{
    /**
     * Persist validated skip rows for a company.
     *
     * @return array{
     *     created: int,
     *     already_skipped: int,
     *     blocked_cancelled: int,
     *     rejected: int
     * }
     */
    public function execute(Company $company, array $validRows, ?User $createdBy = null): array
    {
        $outcomes = [
            'created' => 0,
            'already_skipped' => 0,
            'blocked_cancelled' => 0,
            'rejected' => 0,
        ];

        $recordSkip = new RecordSkip;

        DB::transaction(function () use ($company, $validRows, $createdBy, $recordSkip, &$outcomes) {
            foreach ($validRows as $row) {
                try {
                    $employee = Employee::find($row['employee_id']);
                    if (! $employee || $employee->company_id !== $company->id) {
                        $outcomes['rejected']++;

                        continue;
                    }

                    $result = $recordSkip->execute(
                        $company,
                        $employee,
                        $row['date'],
                        $row['source'] ?? 'leave',
                        $row['reason'] ?? null,
                        $createdBy
                    );

                    match ($result->outcome) {
                        SkipOutcome::CREATED, SkipOutcome::REACTIVATED => $outcomes['created']++,
                        SkipOutcome::ALREADY_SKIPPED => $outcomes['already_skipped']++,
                        SkipOutcome::BLOCKED_CANCELLED => $outcomes['blocked_cancelled']++,
                    };
                } catch (Exception $e) {
                    $outcomes['rejected']++;
                }
            }
        });

        return $outcomes;
    }
}
