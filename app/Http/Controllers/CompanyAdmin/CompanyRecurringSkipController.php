<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Recurring\CreateRecurringSkip;
use App\Actions\Recurring\DeleteRecurringSkip;
use App\Actions\Recurring\PauseRecurringSkip;
use App\Exceptions\MealRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\RecurringSkip;
use App\Support\MealRuleMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompanyRecurringSkipController extends Controller
{
    public function store(Request $request, Employee $employee, CreateRecurringSkip $action)
    {
        $company = Auth::user()->company;
        if (! $company || $employee->company_id !== $company->id) {
            abort(404, 'Employee not found.');
        }

        $validated = $request->validate([
            'weekday' => 'required|integer|between:1,7',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d',
        ]);

        try {
            $action->execute(
                $company,
                $employee,
                (int) $validated['weekday'],
                $validated['starts_on'],
                $validated['ends_on'] ?? null,
                Auth::user()
            );

            return back()->with('message', 'Recurring skip rule created for employee.');
        } catch (MealRuleViolation $e) {
            $msg = MealRuleMessages::getMessage($e->getReasonCode(), $e->getMessage());

            return back()->withErrors(['recurring' => $msg]);
        }
    }

    public function update(Request $request, Employee $employee, RecurringSkip $rule, PauseRecurringSkip $action)
    {
        $company = Auth::user()->company;
        if (! $company || $employee->company_id !== $company->id || $rule->employee_id !== $employee->id) {
            abort(404, 'Rule not found.');
        }

        // Explicit state, not a toggle: a double submit must not switch the rule
        // back on and quietly resume skipping this employee's meals.
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $action->execute($rule, Auth::user(), $validated['active']);

        return back()->with('message', 'Recurring skip rule updated for employee.');
    }

    public function destroy(Request $request, Employee $employee, RecurringSkip $rule, DeleteRecurringSkip $action)
    {
        $company = Auth::user()->company;
        if (! $company || $employee->company_id !== $company->id || $rule->employee_id !== $employee->id) {
            abort(404, 'Rule not found.');
        }

        $action->execute($rule, Auth::user());

        return back()->with('message', 'Recurring skip rule deleted for employee.');
    }
}
