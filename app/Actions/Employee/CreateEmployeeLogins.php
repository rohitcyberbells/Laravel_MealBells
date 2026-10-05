<?php

namespace App\Actions\Employee;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateEmployeeLogins
{
    /**
     * Create user logins for active employees without an account.
     *
     * @return array<int, array{employee_code: string, name: string, temporary_password: string}>
     */
    public function execute(Company $company, ?array $employeeIds = null, ?User $requestedBy = null): array
    {
        if ($requestedBy) {
            if ($requestedBy->company_id !== $company->id) {
                throw new MealRuleViolation('User does not belong to this company.', MealRuleReason::CROSS_COMPANY);
            }
            if ($requestedBy->role !== 'company_admin') {
                throw new MealRuleViolation('Only company admins can generate employee logins.', MealRuleReason::FORBIDDEN_ROLE);
            }
        }

        $query = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNull('user_id');

        if (! empty($employeeIds)) {
            $query->whereIn('id', $employeeIds);
        }

        $employees = $query->get();
        $credentials = [];

        DB::transaction(function () use ($company, $employees, &$credentials) {
            foreach ($employees as $employee) {
                $tempPassword = Str::random(10);
                $email = ! empty($employee->email) ? trim($employee->email) : null;

                if ($email && User::where('email', $email)->exists()) {
                    $email = null;
                }

                if (! $email) {
                    $companyCode = strtolower($company->code ?? 'cmp');
                    $empCode = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $employee->employee_code));
                    $email = "{$empCode}@{$companyCode}.local";

                    $counter = 1;
                    while (User::where('email', $email)->exists()) {
                        $email = "{$empCode}{$counter}@{$companyCode}.local";
                        $counter++;
                    }
                }

                $user = User::create([
                    'name' => $employee->name,
                    'email' => $email,
                    'password' => Hash::make($tempPassword),
                    'role' => 'employee',
                    'company_id' => $company->id,
                    'login_code' => $employee->employee_code,
                    'must_change_password' => true,
                ]);

                $employee->update(['user_id' => $user->id]);

                $credentials[] = [
                    'employee_code' => $employee->employee_code,
                    'name' => $employee->name,
                    'temporary_password' => $tempPassword,
                ];
            }
        });

        return $credentials;
    }
}
