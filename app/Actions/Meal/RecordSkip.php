<?php

namespace App\Actions\Meal;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\User;
use App\Services\MealGuard;

class RecordSkip
{
    public function execute(
        Company $company,
        Employee $employee,
        string $date,
        string $source = 'hr',
        ?string $reason = null,
        ?User $createdBy = null
    ): Skip {
        MealGuard::assertCompanyOwns($company->id, $employee->company_id, 'Employee does not belong to this company.');
        MealGuard::assertEditable($company, $date, ['meal_day', 'locked', 'cutoff'], 'record skip');

        return Skip::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'date' => $date,
            ],
            [
                'company_id' => $company->id,
                'source' => $source,
                'reason' => $reason,
                'created_by' => $createdBy?->id,
                'cancelled_at' => null,
                'cancelled_by' => null,
            ]
        );
    }
}
