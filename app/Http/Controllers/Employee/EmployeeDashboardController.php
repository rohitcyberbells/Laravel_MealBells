<?php

namespace App\Http\Controllers\Employee;

use App\Actions\Meal\CancelSkip;
use App\Actions\Meal\RecordSkip;
use App\Enums\MealRuleReason;
use App\Exceptions\MealRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\DailyOverrides;
use App\Models\MealCount;
use App\Models\RecurringSkip;
use App\Models\Skip;
use App\Models\WeeklyMenu;
use App\Services\MealCalendar;
use App\Support\MealRuleMessages;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EmployeeDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (! $employee || $employee->status !== 'active') {
            abort(403, 'Employee account is inactive or not found.');
        }

        $company = $employee->company;
        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';

        $requestedDate = $request->query('date');
        $todayStr = Carbon::today($timezone)->toDateString();

        $targetDate = ($requestedDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate))
            ? $requestedDate
            : $todayStr;

        $assignment = $company->activeAssignment;
        $tiffinService = $assignment?->tiffinService;

        // Cutoff calculation
        $cutoffTimeStr = $company->setting?->cutoff_time ?? '11:00';
        $parts = explode(':', $cutoffTimeStr);
        $cutoffHour = (int) ($parts[0] ?? 11);
        $cutoffMinute = (int) ($parts[1] ?? 0);

        $cutoffDateTime = Carbon::createFromFormat('Y-m-d', $targetDate, $timezone)->setTime($cutoffHour, $cutoffMinute, 0);
        $now = Carbon::now($timezone);

        $secondsLeft = 0;
        if ($now->lt($cutoffDateTime)) {
            $secondsLeft = max(0, $now->diffInSeconds($cutoffDateTime, false));
        }

        // Everything the page needs for today and the next six days, fetched by
        // range rather than per day. This loop used to cost five queries a day,
        // which was most of the seventy-six this page issued.
        $startCarbon = Carbon::createFromFormat('Y-m-d', $targetDate, $timezone);
        $endDate = $startCarbon->copy()->addDays(6)->toDateString();

        MealCalendar::preload($company, $targetDate, $endDate);

        $skipsByDate = Skip::where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$targetDate, $endDate])
            ->whereNull('cancelled_at')
            ->get()
            ->keyBy(fn (Skip $skip) => Carbon::parse($skip->date)->toDateString());

        $lockedDates = MealCount::where('company_id', $company->id)
            ->whereBetween('date', [$targetDate, $endDate])
            ->whereNotNull('locked_at')
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();

        $overridesByDate = $tiffinService
            ? DailyOverrides::where('tiffin_service_id', $tiffinService->id)
                ->whereBetween('date', [$targetDate, $endDate])
                ->get()
                ->keyBy(fn (DailyOverrides $override) => Carbon::parse($override->date)->toDateString())
            : collect();

        // The week's published menus. A seven-day span touches at most two
        // weeks, so this is one query instead of one per day.
        $menusByWeek = $tiffinService
            ? WeeklyMenu::with('items')
                ->where('tiffin_service_id', $tiffinService->id)
                ->whereIn('week_start_date', [
                    $startCarbon->copy()->startOfWeek()->toDateString(),
                    $startCarbon->copy()->addDays(6)->startOfWeek()->toDateString(),
                ])
                ->where('status', 'published')
                ->get()
                ->keyBy(fn (WeeklyMenu $menu) => Carbon::parse($menu->week_start_date)->toDateString())
            : collect();

        $mealFor = function (string $date) use ($overridesByDate, $menusByWeek, $timezone): ?string {
            if ($override = $overridesByDate->get($date)) {
                return $override->meal_description;
            }

            $moment = Carbon::createFromFormat('Y-m-d', $date, $timezone);
            $menu = $menusByWeek->get($moment->copy()->startOfWeek()->toDateString());

            return $menu?->items
                ?->firstWhere(fn ($item) => strtolower($item->day_of_week) === strtolower($moment->format('l')))
                ?->meal_description;
        };

        // Today details
        $todayMeal = $mealFor($targetDate);
        $todaySkip = $skipsByDate->get($targetDate);
        $todayLocked = in_array($targetDate, $lockedDates, true);
        $todayCalDay = MealCalendar::override($company, $targetDate);
        $todayOverride = $overridesByDate->get($targetDate);

        // Next 7 days list
        $next7Days = [];

        for ($i = 0; $i < 7; $i++) {
            $dCarbon = $startCarbon->copy()->addDays($i);
            $dStr = $dCarbon->toDateString();

            $dSkip = $skipsByDate->get($dStr);
            $dLocked = in_array($dStr, $lockedDates, true);
            $dCalDay = MealCalendar::override($company, $dStr);
            $dOverride = $overridesByDate->get($dStr);

            $next7Days[] = [
                'date' => $dStr,
                'day_name' => $dCarbon->format('l'),
                'is_meal_day' => MealCalendar::isMealDay($company, $dStr),
                'meal' => $mealFor($dStr),
                'has_override' => $dOverride !== null,
                'calendar_day' => $dCalDay ? ['type' => $dCalDay->type, 'note' => $dCalDay->note] : null,
                'status' => $dSkip ? 'skipped' : 'take',
                'skip_source' => $dSkip?->source,
                'skip_id' => $dSkip?->id,
                'can_cancel' => $dSkip && in_array($dSkip->source, ['self', 'recurring']) && ! $dLocked,
            ];
        }

        // My Skips (Active skips for this employee)
        $mySkips = Skip::where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->whereNull('cancelled_at')
            ->orderBy('date', 'asc')
            ->get();

        // My Recurring Rules
        $myRecurringRules = RecurringSkip::where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->orderBy('weekday')
            ->get();

        return Inertia::render('Employee/Dashboard', [
            'today' => [
                'date' => $targetDate,
                'is_meal_day' => MealCalendar::isMealDay($company, $targetDate),
                'meal' => $todayMeal,
                'has_override' => $todayOverride !== null,
                'calendar_day' => $todayCalDay ? ['type' => $todayCalDay->type, 'note' => $todayCalDay->note] : null,
                'status' => $todaySkip ? 'skipped' : 'take',
                'skip_source' => $todaySkip?->source,
                'can_cancel' => $todaySkip && in_array($todaySkip->source, ['self', 'recurring']) && ! $todayLocked && $now->lt($cutoffDateTime),
                'cutoff_time' => substr($cutoffTimeStr, 0, 5),
                'seconds_left' => $secondsLeft,
                'locked' => $todayLocked,
            ],
            'next_7_days' => $next7Days,
            'my_skips' => $mySkips,
            'my_recurring_rules' => $myRecurringRules,
            'active_tiffin_assigned' => $tiffinService !== null,
        ]);
    }

    public function storeSkip(Request $request, RecordSkip $action)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (! $employee || $employee->status !== 'active') {
            return back()->withErrors(['skip' => 'Employee account is inactive.']);
        }

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $action->execute(
                $employee->company,
                $employee,
                $validated['date'],
                'self',
                $validated['reason'] ?? null,
                $user
            );

            return back()->with('message', 'Meal skip recorded successfully.');
        } catch (MealRuleViolation $e) {
            $msg = MealRuleMessages::getMessage($e->getReasonCode(), $e->getMessage());

            return back()->withErrors(['skip' => $msg]);
        }
    }

    public function storeSkipRange(Request $request, RecordSkip $action)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (! $employee || $employee->status !== 'active') {
            return back()->withErrors(['skip' => 'Employee account is inactive.']);
        }

        $validated = $request->validate([
            'from_date' => 'required|date_format:Y-m-d',
            'to_date' => 'required|date_format:Y-m-d',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validated['to_date'] < $validated['from_date']) {
            return back()->withErrors(['to_date' => 'to_date cannot be earlier than from_date.']);
        }

        $start = Carbon::parse($validated['from_date']);
        $end = Carbon::parse($validated['to_date']);

        if ($start->diffInDays($end) + 1 > 31) {
            return back()->withErrors(['to_date' => 'Date range cannot exceed 31 days.']);
        }

        $company = $employee->company;
        $current = $start->copy();
        $count = 0;

        while ($current->lte($end)) {
            $dStr = $current->toDateString();
            if (MealCalendar::isMealDay($company, $dStr)) {
                try {
                    $action->execute($company, $employee, $dStr, 'self', $validated['reason'] ?? null, $user);
                    $count++;
                } catch (MealRuleViolation $e) {
                    // Skip invalid dates in range
                }
            }
            $current->addDay();
        }

        // Days the engine refused are passed over rather than failing the whole
        // range, so nothing at all can come back - and a green "recorded for 0
        // meal days" reads as success when the request did nothing.
        if ($count === 0) {
            return back()->withErrors([
                'skip' => 'Nothing was skipped: that range has no meal day still open for a change.',
            ]);
        }

        return back()->with('message', "Skips recorded for {$count} meal days.");
    }

    public function destroySkip(Request $request, Skip $skip, CancelSkip $action)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (! $employee || $skip->employee_id !== $employee->id) {
            abort(404, 'Skip record not found.');
        }

        if (! in_array($skip->source, ['self', 'recurring'], true)) {
            $msg = MealRuleMessages::getMessage(MealRuleReason::EMPLOYEE_CANNOT_CANCEL_SYSTEM_SKIP);

            return back()->withErrors(['skip' => $msg]);
        }

        try {
            $action->execute($employee->company, $skip, $user);

            return back()->with('message', 'Meal skip cancelled successfully.');
        } catch (MealRuleViolation $e) {
            $msg = MealRuleMessages::getMessage($e->getReasonCode(), $e->getMessage());

            return back()->withErrors(['skip' => $msg]);
        }
    }
}
