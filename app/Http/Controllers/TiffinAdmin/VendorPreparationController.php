<?php

namespace App\Http\Controllers\TiffinAdmin;

use App\Actions\Meal\BuildVendorPreparationView;
use App\Http\Controllers\Controller;
use App\Models\DailyOverrides;
use App\Models\WeeklyMenu;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class VendorPreparationController extends Controller
{
    public function show(Request $request, BuildVendorPreparationView $action)
    {
        $user = Auth::user();
        $tiffinId = $user?->tiffin_service_id;

        // Through the action, which memoizes it: both were running the same
        // three-query lookup for the same answer.
        $timezone = $action->timezoneFor($tiffinId);
        $date = $request->query('date', Carbon::today($timezone)->toDateString());

        $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $user = Auth::user();
        if (! $user->tiffin_service_id || ! $user->tiffinService) {
            return Inertia::render('TiffinAdmin/Preparation', [
                'tiffinService' => null,
                'preparationData' => null,
                'selectedDate' => $date,
                'todayOverride' => null,
                'todayMenu' => null,
            ]);
        }

        $tiffinService = $user->tiffinService;
        $prepData = $action->execute($tiffinService, $date);

        // Fetch today's override if any
        $todayOverride = DailyOverrides::where('tiffin_service_id', $tiffinService->id)
            ->where('date', $date)
            ->first();

        // Fetch menu for selected date's day of week
        $dayName = Carbon::parse($date)->format('l');
        $weekStart = Carbon::parse($date)->startOfWeek()->toDateString();
        $weeklyMenu = WeeklyMenu::with('items')
            ->where('tiffin_service_id', $tiffinService->id)
            ->where('week_start_date', $weekStart)
            ->first();

        $todayMenuItem = $weeklyMenu?->items?->firstWhere(fn ($item) => strtolower($item->day_of_week) === strtolower($dayName));

        return Inertia::render('TiffinAdmin/Preparation', [
            'tiffinService' => $tiffinService,
            'preparationData' => $prepData,
            'selectedDate' => $date,
            'todayOverride' => $todayOverride,
            'todayMenu' => $todayMenuItem?->meal_description,
        ]);
    }
}
