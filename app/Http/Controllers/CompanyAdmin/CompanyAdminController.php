<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Meal\CalculateExpectedMeals;
use App\Http\Controllers\Controller;
use App\Models\CompanyTiffinAssignment;
use App\Models\DailyOverrides;
use App\Models\MealCount;
use App\Models\WeeklyMenu;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CompanyAdminController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            return Inertia::render('CompanyAdmin/Dashboard', [
                'company' => null,
                'tiffinService' => null,
                'weeklyMenu' => null,
                'todayOverride' => null,
                'todayMeal' => null,
                'notifications' => [],
                'todayStats' => null,
                'forecast' => [],
            ]);
        }

        $timezone = $company->setting?->timezone ?? 'Asia/Kolkata';
        $todayDate = Carbon::today($timezone)->toDateString();

        // 1. Fetch active assigned Tiffin Service
        $assignment = CompanyTiffinAssignment::with('tiffinService')
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->first();

        $tiffinService = $assignment?->tiffinService;

        // 2. Fetch Notifications
        $notifications = $user->notifications()
            ->latest()
            ->take(10)
            ->get();

        // 3. Resolve Today's Meal (DailyOverride > Published WeeklyMenu)
        $todayOverride = null;
        $todayMeal = null;
        $weeklyMenu = null;

        if ($tiffinService) {
            $todayOverride = DailyOverrides::where('tiffin_service_id', $tiffinService->id)
                ->where('date', $todayDate)
                ->first();

            $weekStart = Carbon::today($timezone)->startOfWeek()->toDateString();
            $weeklyMenu = WeeklyMenu::with('items')
                ->where('tiffin_service_id', $tiffinService->id)
                ->where('week_start_date', $weekStart)
                ->where('status', 'published')
                ->first();

            $dayName = Carbon::today($timezone)->format('l');
            $plannedItem = $weeklyMenu?->items?->firstWhere(fn ($item) => strtolower($item->day_of_week) === strtolower($dayName));

            if ($todayOverride) {
                $todayMeal = [
                    'meal_description' => $todayOverride->meal_description,
                    'reason' => $todayOverride->reason,
                    'is_override' => true,
                ];
            } elseif ($plannedItem) {
                $todayMeal = [
                    'meal_description' => $plannedItem->meal_description,
                    'reason' => null,
                    'is_override' => false,
                ];
            }
        }

        // 4. Calculate Today's Stats & Forecast (Next 7 days)
        $calculator = new CalculateExpectedMeals;

        // Today's lock status check
        $todayLockedSnapshot = MealCount::where('company_id', $company->id)
            ->where('date', $todayDate)
            ->whereNotNull('locked_at')
            ->first();

        $todayCalculated = $calculator->execute($company, $todayDate);
        $todayStats = array_merge($todayCalculated, [
            'is_locked' => $todayLockedSnapshot !== null,
            'status' => $todayLockedSnapshot ? $todayLockedSnapshot->status : ($todayCalculated['is_meal_day'] ? 'open' : 'non_meal_day'),
            'adjusted_total' => $todayLockedSnapshot ? $todayLockedSnapshot->adjusted_total : $todayCalculated['final_expected_count'],
        ]);

        // 7-day Forecast
        $forecast = [];
        for ($i = 0; $i < 7; $i++) {
            $forecastDate = Carbon::today($timezone)->addDays($i)->toDateString();
            $forecastCalculated = $calculator->execute($company, $forecastDate);

            $lockedSnapshot = MealCount::where('company_id', $company->id)
                ->where('date', $forecastDate)
                ->whereNotNull('locked_at')
                ->first();

            $forecast[] = array_merge($forecastCalculated, [
                'is_locked' => $lockedSnapshot !== null,
                'status' => $lockedSnapshot ? $lockedSnapshot->status : ($forecastCalculated['is_meal_day'] ? 'open' : 'non_meal_day'),
                'adjusted_total' => $lockedSnapshot ? $lockedSnapshot->adjusted_total : $forecastCalculated['final_expected_count'],
            ]);
        }

        return Inertia::render('CompanyAdmin/Dashboard', [
            'company' => $company->load('setting'),
            'tiffinService' => $tiffinService,
            'weeklyMenu' => $weeklyMenu,
            'todayOverride' => $todayOverride,
            'todayMeal' => $todayMeal,
            'notifications' => $notifications,
            'todayStats' => $todayStats,
            'forecast' => $forecast,
        ]);
    }
}
