<?php

namespace App\Observers;

use App\Actions\Recurring\PauseRecurringSkip;
use App\Models\Employee;
use App\Models\RecurringSkip;
use Illuminate\Support\Facades\Auth;

class EmployeeObserver
{
    public function updated(Employee $employee): void
    {
        $becameInactive = $employee->isDirty('status') && $employee->status === 'inactive';
        $becameIneligible = $employee->isDirty('is_meal_eligible') && ! $employee->is_meal_eligible;

        if ($becameInactive || $becameIneligible) {
            $rules = RecurringSkip::where('employee_id', $employee->id)
                ->where('active', true)
                ->get();

            $pauseAction = new PauseRecurringSkip;
            $user = Auth::user();

            foreach ($rules as $rule) {
                // Explicitly off, rather than relying on a toggle.
                $pauseAction->execute($rule, $user, false);
            }
        }
    }
}
