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
     * @return array{
     *     employee_code: string,
     *     name: string,
     *     temporary_password: string,
     *     email: ?string,
     *     can_login_with_email: bool,
     *     mail_sent: bool,
     *     company_code: ?string
     * }
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
            // users.email is NOT NULL and unique, so an employee with no address
            // - or one already held by another account - needs the same stand-in
            // CreateEmployeeLogins uses. Passing the raw value through put a
            // null or a duplicate into the column and failed the insert.
            $email = ! empty($employee->email) && ! User::where('email', trim($employee->email))->exists()
                ? trim($employee->email)
                : CreateEmployeeLogins::placeholderEmail($company, $employee->employee_code);

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
        } else {
            $user = $employee->user;
            if ($user) {
                $user->update([
                    'password' => Hash::make($tempPassword),
                    'must_change_password' => true,
                ]);
            }
        }

        // Usable only when the account actually carries the employee's own
        // address: a stand-in is never something they can sign in with. Compared
        // against the employee rather than matched on the placeholder's shape,
        // so the panel does not depend on how that stand-in is spelled. No mail
        // goes out on a reset - the admin hands the password over.
        $employeeEmail = ! empty($employee->email) ? strtolower(trim($employee->email)) : null;
        $usableEmail = $user && $employeeEmail && strtolower((string) $user->email) === $employeeEmail
            ? $user->email
            : null;

        return [
            'employee_code' => $employee->employee_code,
            'name' => $employee->name,
            'temporary_password' => $tempPassword,
            'email' => $usableEmail,
            'can_login_with_email' => $usableEmail !== null,
            'mail_sent' => false,
            'company_code' => $company->code,
        ];
    }
}
