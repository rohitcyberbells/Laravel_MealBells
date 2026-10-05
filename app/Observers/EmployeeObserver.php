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
        if ($employee->isDirty('status') && $employee->status === 'inactive') {
            $rules = RecurringSkip::where('employee_id', $employee->id)
                ->where('active', true)
                ->get();

            $pauseAction = new PauseRecurringSkip;
            $user = Auth::user();

            foreach ($rules as $rule) {
                $pauseAction->execute($rule, $user);
            }
        }
    }
}
