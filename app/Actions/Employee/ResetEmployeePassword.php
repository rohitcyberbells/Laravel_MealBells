<?php

namespace App\Actions\Employee;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetEmployeePassword
{
    /**
     * Reset password for a specific employee.
     *
     * @return array{employee_code: string, name: string, temporary_password: string}
     */
    public function execute(Company $company, Employee $employee, ?User $requestedBy = null): array
    {
        if ($employee->company_id !== $company->id) {
            throw new MealRuleViolation('Employee does not belong to this company.', MealRuleReason::CROSS_COMPANY);
        }

        if ($requestedBy) {
            if ($requestedBy->company_id !== $company->id) {
                throw new MealRuleViolation('User does not belong to this company.', MealRuleReason::CROSS_COMPANY);
            }
            if ($requestedBy->role !== 'company_admin') {
                throw new MealRuleViolation('Only company admins can reset employee passwords.', MealRuleReason::FORBIDDEN_ROLE);
            }
        }

        $tempPassword = Str::random(10);

        if (! $employee->user_id) {
            $user = User::create([
                'name' => $employee->name,
                'email' => $employee->email,
                'password' => Hash::make($tempPassword),
                'role' => 'employee',
                'company_id' => $company->id,
                'login_code' => $employee->employee_code,
                'must_change_password' => true,
            ]);

            $employee->update(['user_id' => $user->id]);
        } else {
            $user = $employee->user;
            if ($user) {
                $user->update([
                    'password' => Hash::make($tempPassword),
                    'must_change_password' => true,
                ]);
            }
        }

        return [
            'employee_code' => $employee->employee_code,
            'name' => $employee->name,
            'temporary_password' => $tempPassword,
        ];
    }
}
