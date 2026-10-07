<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Calendar\RemoveCalendarDay;
use App\Actions\Calendar\SetCalendarDay;
use App\Exceptions\MealRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\CompanyCalendarDay;
use App\Support\MealRuleMessages;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CompanyCalendarController extends Controller
{
    public function index(Request $request)
    {
        $company = Auth::user()->company;
        if (! $company) {
            abort(404, 'Company not found.');
        }

        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';

        $monthParam = $request->query('month');
        if (! $monthParam || ! preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $month = Carbon::today($timezone)->format('Y-m');
        } else {
            $month = $monthParam;
        }

        $startOfMonth = Carbon::createFromFormat('Y-m', $month, $timezone)->startOfMonth()->toDateString();
        $endOfMonth = Carbon::createFromFormat('Y-m', $month, $timezone)->endOfMonth()->toDateString();

        $calendarDays = CompanyCalendarDay::where('company_id', $company->id)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->orderBy('date')
            ->get();

        $mealDays = $company->setting?->meal_days ?? [1, 2, 3, 4, 5];

        return Inertia::render('CompanyAdmin/Calendar/Index', [
            'month' => $month,
            'days' => $calendarDays,
            'meal_days' => $mealDays,
        ]);
    }

    public function store(Request $request, SetCalendarDay $action)
    {
        $company = Auth::user()->company;
        if (! $company) {
            return back()->withErrors(['company' => 'User does not belong to a company.']);
        }

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'type' => 'required|in:holiday,working_day',
            'note' => 'nullable|string|max:255',
        ]);

        try {
            $action->execute(
                $company,
                $validated['date'],
                $validated['type'],
                $validated['note'] ?? null,
                Auth::user()
            );

            return back()->with('message', 'Calendar day updated successfully.');
        } catch (MealRuleViolation $e) {
            $msg = MealRuleMessages::getMessage($e->getReasonCode(), $e->getMessage());

            return back()->withErrors(['calendar' => $msg]);
        }
    }

    public function storeBulk(Request $request, SetCalendarDay $action)
    {
        $company = Auth::user()->company;
        if (! $company) {
            return back()->withErrors(['company' => 'User does not belong to a company.']);
        }

        $validated = $request->validate([
            'dates' => 'required|array|min:1',
            'dates.*' => 'required|date_format:Y-m-d',
            'type' => 'required|in:holiday,working_day',
            'note' => 'nullable|string|max:255',
        ]);

        $count = 0;
        foreach ($validated['dates'] as $dateStr) {
            try {
                $action->execute(
                    $company,
                    $dateStr,
                    $validated['type'],
                    $validated['note'] ?? null,
                    Auth::user()
                );
                $count++;
            } catch (MealRuleViolation $e) {
                // Ignore invalid/past dates in bulk operation
            }
        }

        // Past and locked dates are passed over rather than failing the whole
        // call, so a request can come back having done nothing at all - and
        // "Updated 0 calendar days successfully" reads as if it worked.
        if ($count === 0) {
            return back()->withErrors([
                'calendar' => 'Nothing was changed: none of those dates can still be edited.',
            ]);
        }

        $noun = $count === 1 ? 'day' : 'days';

        return back()->with('message', "Updated {$count} calendar {$noun} successfully.");
    }

    public function destroy(Request $request, CompanyCalendarDay $day, RemoveCalendarDay $action)
    {
        $company = Auth::user()->company;
        if (! $company || $day->company_id !== $company->id) {
            abort(404, 'Calendar day not found.');
        }

        try {
            $action->execute($company, $day->date);

            return back()->with('message', 'Calendar day reset successfully.');
        } catch (MealRuleViolation $e) {
            $msg = MealRuleMessages::getMessage($e->getReasonCode(), $e->getMessage());

            return back()->withErrors(['calendar' => $msg]);
        }
    }
}
