<?php

namespace App\Http\Controllers\Employee;

use App\Actions\Recurring\CreateRecurringSkip;
use App\Actions\Recurring\DeleteRecurringSkip;
use App\Actions\Recurring\PauseRecurringSkip;
use App\Exceptions\MealRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\RecurringSkip;
use App\Support\MealRuleMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecurringSkipController extends Controller
{
    public function store(Request $request, CreateRecurringSkip $action)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (! $employee || $employee->status !== 'active') {
            return back()->withErrors(['recurring' => 'Employee account is inactive.']);
        }

        $validated = $request->validate([
            'weekday' => 'required|integer|between:1,7',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d',
        ]);

        try {
            $action->execute(
                $employee->company,
                $employee,
                (int) $validated['weekday'],
                $validated['starts_on'],
                $validated['ends_on'] ?? null,
                $user
            );

            return back()->with('message', 'Recurring skip rule created successfully.');
        } catch (MealRuleViolation $e) {
            $msg = MealRuleMessages::getMessage($e->getReasonCode(), $e->getMessage());

            return back()->withErrors(['recurring' => $msg]);
        }
    }

    public function update(Request $request, RecurringSkip $rule, PauseRecurringSkip $action)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (! $employee || $rule->employee_id !== $employee->id) {
            abort(404, 'Recurring rule not found.');
        }

        // Explicit state, not a toggle: a double submit must not switch the rule
        // back on and quietly resume skipping meals.
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $action->execute($rule, $user, $validated['active']);

        return back()->with('message', 'Recurring rule updated successfully.');
    }

    public function destroy(Request $request, RecurringSkip $rule, DeleteRecurringSkip $action)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (! $employee || $rule->employee_id !== $employee->id) {
            abort(404, 'Recurring rule not found.');
        }

        $action->execute($rule, $user);

        return back()->with('message', 'Recurring rule deleted successfully.');
    }
}
