<?php

namespace App\Http\Controllers\TiffinAdmin;

use App\Http\Controllers\Controller;
use App\Models\CompanyTiffinAssignment;
use App\Models\DailyOverrides;
use App\Models\MenuItem;
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

        // Current week Monday date
        $weekStart = Carbon::now()->startOfWeek()->toDateString();

        // Fetch active assigned company
        $assignment = CompanyTiffinAssignment::with('company')
            ->where('tiffin_service_id', $tiffinId)
            ->where('is_active', true)
            ->first();

        // Fetch or prepare current weekly menu
        $weeklyMenu = WeeklyMenu::with('items')
            ->where('tiffin_service_id', $tiffinId)
            ->where('week_start_date', $weekStart)
            ->first();

        // Fetch today's override if any
        $todayOverride = DailyOverrides::where('tiffin_service_id', $tiffinId)
            ->where('date', Carbon::today()->toDateString())
            ->first();

        return Inertia::render('TiffinAdmin/Dashboard', [
            'tiffinService' => $user->tiffinService,
            'assignedCompany' => $assignment?->company,
            'weeklyMenu' => $weeklyMenu,
            'weekStartDate' => $weekStart,
            'todayOverride' => $todayOverride,
        ]);
    }

    public function saveMenu(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'week_start_date' => 'required|date',
            'status' => 'required|in:draft,published',
            'items' => 'required|array',
            'items.*.day_of_week' => 'required|string',
            'items.*.meal_description' => 'required|string',
        ]);

        // Find or create weekly menu
        $menu = WeeklyMenu::updateOrCreate(
            [
                'tiffin_service_id' => $user->tiffin_service_id,
                'week_start_date' => $validated['week_start_date'],
            ],
            [
                'status' => $validated['status'],
            ]
        );

        // Save menu items for each day
        foreach ($validated['items'] as $itemData) {
            MenuItem::updateOrCreate(
                [
                    'weekly_menu_id' => $menu->id,
                    'day_of_week' => strtolower($itemData['day_of_week']),
                ],
                [
                    'meal_description' => $itemData['meal_description'],
                ]
            );
        }

        return back()->with('message', 'Weekly menu saved successfully!');
    }

    public function storeOverride(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'meal_description' => 'required|string',
            'reason' => 'nullable|string',
        ]);

        DailyOverrides::updateOrCreate(
            [
                'tiffin_service_id' => $user->tiffin_service_id,
                'date' => Carbon::today()->toDateString(),
            ],
            [
                'meal_description' => $validated['meal_description'],
                'reason' => $validated['reason'],
            ]
        );

        return back()->with('message', "Today's meal override posted successfully!");
    }
}
