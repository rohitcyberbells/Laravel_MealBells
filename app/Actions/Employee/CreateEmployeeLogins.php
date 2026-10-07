<?php

namespace App\Actions\Employee;

use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeLoginCreatedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateEmployeeLogins
{
    /**
     * Create user logins for active employees without an account.
     *
     * Each credential reports how that employee can actually sign in. An
     * employee with a usable address gets the password by mail as well; one
     * without can only use the company code and employee code, and the caller is
     * told so rather than left to guess why no mail arrived.
     *
     * @return array<int, array{
     *     employee_code: string,
     *     name: string,
     *     temporary_password: string,
     *     email: ?string,
     *     can_login_with_email: bool,
     *     mail_sent: bool,
     *     company_code: ?string
     * }>
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

        $pendingMail = [];

        DB::transaction(function () use ($company, $employees, &$credentials, &$pendingMail) {
            foreach ($employees as $employee) {
                $tempPassword = Str::random(10);
                $realEmail = ! empty($employee->email) ? trim($employee->email) : null;

                // An address already held by another user cannot be reused: the
                // column is unique, and the other account would own the login.
                if ($realEmail && User::where('email', $realEmail)->exists()) {
                    $realEmail = null;
                }

                $email = $realEmail;

                if (! $email) {
                    $email = self::placeholderEmail($company, $employee->employee_code);
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
                    'email' => $realEmail,
                    'can_login_with_email' => $realEmail !== null,
                    'mail_sent' => $realEmail !== null,
                    'company_code' => $company->code,
                ];

                if ($realEmail) {
                    // Collected rather than sent inside the transaction, so a
                    // later rollback cannot leave a password already delivered.
                    $pendingMail[] = [$user, $tempPassword, $employee->employee_code];
                }
            }
        });

        foreach ($pendingMail as [$user, $tempPassword, $employeeCode]) {
            $user->notify(new EmployeeLoginCreatedNotification($company, $employeeCode, $tempPassword));
        }

        return $credentials;
    }

    /**
     * A stand-in address for an employee who has none.
     *
     * users.email is NOT NULL and unique, so an account still needs one; this
     * is never mailed and the employee signs in with the company code and their
     * employee code instead. Shared with the demo seeder so the convention is
     * defined once.
     */
    public static function placeholderEmail(Company $company, string $employeeCode): string
    {
        $companyCode = strtolower($company->code ?? 'cmp');
        $empCode = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $employeeCode));

        $suffix = config('mealbells.placeholder_email_suffix', '.local');

        $email = "{$empCode}@{$companyCode}{$suffix}";
        $counter = 1;

        while (User::where('email', $email)->exists()) {
            $email = "{$empCode}{$counter}@{$companyCode}{$suffix}";
            $counter++;
        }

        return $email;
    }
}
