<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Meal\AcknowledgeDailyCount;
use App\Actions\Meal\CalculateExpectedMeals;
use App\Actions\Meal\RecordPostCutoffChange;
use App\Exceptions\MealRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\MealAdjustment;
use App\Models\MealCount;
use App\Models\MealCountChange;
use App\Models\Skip;
use App\Services\MealCalendar;
use App\Support\MealRuleMessages;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CompanyDailyController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            abort(404, 'Company not found.');
        }

        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
        $today = Carbon::today($timezone)->toDateString();
        $advanceLimitDays = $company->setting?->advance_limit_days ?? 14;

        $minDate = Carbon::today($timezone)->subDays(7)->toDateString();
        $maxDate = Carbon::today($timezone)->addDays($advanceLimitDays)->toDateString();

        $requestedDate = $request->query('date');

        if (! $requestedDate || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
            $date = $today;
        } else {
            if ($requestedDate < $minDate || $requestedDate > $maxDate) {
                return redirect()->route('company-admin.daily.index');
            }
            $date = $requestedDate;
        }

        $isMealDay = MealCalendar::isMealDay($company, $date);

        // Fetch locked snapshot if present
        $lockedSnapshot = MealCount::where('company_id', $company->id)
            ->where('date', $date)
            ->whereNotNull('locked_at')
            ->first();

        $calculator = new CalculateExpectedMeals;
        $calculatedData = $calculator->execute($company, $date);

        $changes = [];
        if ($lockedSnapshot) {
            $status = 'locked';
            $changes = MealCountChange::where('meal_count_id', $lockedSnapshot->id)
                ->with('requestedBy:id,name,email')
                ->latest()
                ->get();

            $countData = [
                'base_eligible_count' => $lockedSnapshot->base_eligible_count,
                'skip_count' => $lockedSnapshot->skip_count,
                'extra_count' => $lockedSnapshot->extra_count,
                'final_expected_count' => $lockedSnapshot->final_expected_count,
                'adjusted_total' => $lockedSnapshot->adjusted_total,
                'breakdown' => $lockedSnapshot->breakdown ?? [],
                'changes' => $changes,
            ];
        } else {
            $status = 'estimate';
            $countData = [
                'base_eligible_count' => $calculatedData['base_eligible_count'],
                'skip_count' => $calculatedData['skip_count'],
                'extra_count' => $calculatedData['extra_count'],
                'final_expected_count' => $calculatedData['final_expected_count'],
                'adjusted_total' => $calculatedData['final_expected_count'],
                'breakdown' => $calculatedData['breakdown'],
                'changes' => [],
            ];
        }

        // Cutoff time & Seconds Left Calculation
        $cutoffTimeStr = $company->setting?->cutoff_time ?? '11:00';
        $cutoffParts = explode(':', $cutoffTimeStr);
        $cutoffHour = (int) ($cutoffParts[0] ?? 11);
        $cutoffMinute = (int) ($cutoffParts[1] ?? 0);

        $cutoffDateTime = Carbon::createFromFormat('Y-m-d', $date, $timezone)
            ->setTime($cutoffHour, $cutoffMinute, 0);

        $now = Carbon::now($timezone);

        $secondsLeft = 0;
        if (! $lockedSnapshot && $now->lt($cutoffDateTime)) {
            $secondsLeft = max(0, $now->diffInSeconds($cutoffDateTime, false));
        }

        // Active & Cancelled Skips for date
        $skips = Skip::where('company_id', $company->id)
            ->where('date', $date)
            ->with('employee:id,name,employee_code')
            ->orderBy('id', 'desc')
            ->get();

        // Extra Meals for date
        $extraMeals = MealAdjustment::where('company_id', $company->id)
            ->where('date', $date)
            ->with('requestedBy:id,name')
            ->orderBy('id', 'desc')
            ->get();

        // Active & Eligible Employees for search dropdown
        $employeesForSearch = Employee::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('is_meal_eligible', true)
            ->select('id', 'name', 'employee_code')
            ->orderBy('name')
            ->get();

        return Inertia::render('CompanyAdmin/Daily/Index', [
            'date' => $date,
            'is_meal_day' => $isMealDay,
            'count' => $countData,
            'status' => $status,
            'cutoff_time' => substr($cutoffTimeStr, 0, 5),
            'seconds_left' => $secondsLeft,
            'skips' => $skips,
            'extra_meals' => $extraMeals,
            'employees_for_search' => $employeesForSearch,
        ]);
    }

    public function recordLateChange(Request $request, RecordPostCutoffChange $action)
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            return back()->withErrors(['company' => 'User is not associated with any company.']);
        }

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'change_quantity' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        try {
            $action->execute(
                $company,
                $validated['date'],
                (int) $validated['change_quantity'],
                $validated['reason'],
                $user
            );

            return back()->with('message', 'Post-cutoff meal change recorded successfully.');
        } catch (MealRuleViolation $e) {
            $msg = MealRuleMessages::getMessage($e->getReasonCode(), $e->getMessage());

            return back()->withErrors(['late_change' => $msg]);
        } catch (\Exception $e) {
            return back()->withErrors(['late_change' => $e->getMessage()]);
        }
    }

    public function acknowledge(Request $request, AcknowledgeDailyCount $action)
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            return back()->withErrors(['company' => 'User is not associated with any company.']);
        }

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        try {
            $action->execute($company, $validated['date'], $user);

            return back()->with('message', 'Daily count acknowledged successfully.');
        } catch (MealRuleViolation $e) {
            $msg = MealRuleMessages::getMessage($e->getReasonCode(), $e->getMessage());

            return back()->withErrors(['acknowledge' => $msg]);
        }
    }
}
