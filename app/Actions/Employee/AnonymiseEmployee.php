<?php

namespace App\Actions\Employee;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Skip;
use App\Models\User;
use App\Services\MealGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Removes a person from MealBells without removing what they ate.
 *
 * A data-subject request has two halves that pull against each other: the
 * person's identity has to go, and the company's records have to stay
 * arithmetically intact. Deleting the employee row would satisfy the first and
 * destroy the second - every skip they ever had cascades, so the count the
 * kitchen was given for last Tuesday would no longer be reproducible from the
 * data behind it, and that count is the evidence in a billing dispute.
 *
 * So the row stays and stops identifying anyone: no name, no address, no
 * employee code, no HRMS id, no login. The skips stay, dates and sources
 * intact, with any free-text reason removed - "dental surgery follow-up" is
 * exactly the kind of detail this is meant to erase.
 */
class AnonymiseEmployee
{
    public const REMOVED_NAME = 'Removed employee';

    /**
     * Idempotent: asking twice is the same as asking once, which matters
     * because the only record of having asked is on the row itself.
     */
    public function execute(Company $company, Employee $employee, User $requestedBy): Employee
    {
        MealGuard::assertCompanyOwns($company->id, $employee->company_id, 'Employee does not belong to this company.');

        if ($employee->anonymised_at !== null) {
            return $employee;
        }

        return DB::transaction(function () use ($employee, $requestedBy) {
            // The skip rows survive; only the free text on them goes. Updated
            // through the builder deliberately - there is nothing to observe
            // here, and a company with years of history should not load every
            // skip into memory to blank one column.
            Skip::where('employee_id', $employee->id)
                ->whereNotNull('reason')
                ->update(['reason' => null]);

            $this->anonymiseLogin($employee);

            $employee->forceFill([
                'name' => self::REMOVED_NAME,
                'email' => null,
                // Unique per company, and the id is already unique, so this
                // cannot collide. It still reads as deliberate rather than
                // corrupt to anyone looking at the table.
                'employee_code' => 'ANON-'.$employee->id,
                // Cleared, or the next HRMS pull would match this person again
                // by their id on the HR side and re-identify them.
                'external_id' => null,
                'status' => 'inactive',
                'is_meal_eligible' => false,
                'anonymised_at' => now(),
                'anonymised_by' => $requestedBy->id,
            ])->save();

            return $employee;
        });
    }

    /**
     * The login goes the same way, and is deactivated rather than deleted so
     * that everything it created - skips, confirmations - keeps its
     * attribution.
     */
    protected function anonymiseLogin(Employee $employee): void
    {
        $user = $employee->user_id ? User::find($employee->user_id) : null;

        if ($user === null) {
            return;
        }

        $suffix = (string) config('mealbells.placeholder_email_suffix', '.local');

        $user->forceFill([
            'name' => self::REMOVED_NAME,
            // users.email is unique across the whole platform, so the id is
            // what keeps this collision-free. The placeholder suffix is the
            // one the application already treats as unreachable, so nothing
            // will try to mail it.
            'email' => 'anonymised-'.$user->id.$suffix,
            'login_code' => null,
            'password' => bcrypt(Str::random(40)),
            'is_active' => false,
            'deactivated_at' => now(),
        ])->save();
    }
}
