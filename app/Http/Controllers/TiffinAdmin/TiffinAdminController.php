<?php

namespace App\Http\Controllers\TiffinAdmin;

use App\Actions\Meal\CreateDailyOverride;
use App\Actions\Menu\SaveWeeklyMenu;
use App\Http\Controllers\Controller;
use App\Models\CompanyTiffinAssignment;
use App\Models\DailyOverrides;
use App\Models\WeeklyMenu;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TiffinAdminController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $tiffinId = $user->tiffin_service_id;

        // Fetch active assigned company
        $assignment = CompanyTiffinAssignment::with('company.setting')
            ->where('tiffin_service_id', $tiffinId)
            ->where('is_active', true)
            ->first();

        $timezone = $assignment?->company?->setting?->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');

        // Current week Monday date
        $weekStart = Carbon::now($timezone)->startOfWeek()->toDateString();
        $todayDate = Carbon::today($timezone)->toDateString();

        // Fetch or prepare current weekly menu
        $weeklyMenu = WeeklyMenu::with('items')
            ->where('tiffin_service_id', $tiffinId)
            ->where('week_start_date', $weekStart)
            ->first();

        // Fetch today's override if any
        $todayOverride = DailyOverrides::where('tiffin_service_id', $tiffinId)
            ->where('date', $todayDate)
            ->first();

        return Inertia::render('TiffinAdmin/Dashboard', [
            'tiffinService' => $user->tiffinService,
            'assignedCompany' => $assignment?->company,
            'weeklyMenu' => $weeklyMenu,
            'weekStartDate' => $weekStart,
            'todayOverride' => $todayOverride,
        ]);
    }

    public function saveMenu(Request $request, SaveWeeklyMenu $action)
    {
        $validated = $request->validate([
            'week_start_date' => 'required|date',
            'status' => 'required|in:draft,published',
            'items' => 'required|array',
            'items.*.day_of_week' => 'required|string',
            'items.*.meal_description' => 'required|string',
        ]);

        $action->execute($request->user(), $validated);

        return back()->with('message', 'Weekly menu saved successfully!');
    }

    public function storeOverride(Request $request, CreateDailyOverride $action)
    {
        $validated = $request->validate([
            'meal_description' => 'required|string',
            'reason' => 'nullable|string',
        ]);

        $action->execute($request->user(), $validated);

        return back()->with('message', "Today's meal override posted successfully!");
    }
}
