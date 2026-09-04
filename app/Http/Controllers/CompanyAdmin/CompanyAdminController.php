<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Http\Controllers\Controller;
use App\Models\CompanyTiffinAssignment;
use App\Models\DailyOverrides;
use App\Models\WeeklyMenu;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CompanyAdminController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        // Fetch active assignment to get assigned tiffin service  
        $assignment = CompanyTiffinAssignment::with('tiffinService')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        $tiffinService = $assignment?->tiffinService;
        $weeklyMenu = null;
        $todayOverride = null;
        $todayMeal = null;

        if ($tiffinService) {
            $weekStart = Carbon::now()->startOfWeek()->toDateString();
            $todayName = strtolower(Carbon::now()->format('l'));
            $todayDate = Carbon::today()->toDateString();

            // Fetch published weekly menu ONLY
            $weeklyMenu = WeeklyMenu::with('items')
                ->where('tiffin_service_id', $tiffinService->id)
                ->where('week_start_date', $weekStart)
                ->where('status', 'published')
                ->first();

            // Fetch today's override
            $todayOverride = DailyOverrides::where('tiffin_service_id', $tiffinService->id)
                ->where('date', $todayDate)
                ->first();

            // Override takes priority over planned menu item
            if ($todayOverride) {
                $todayMeal = [
                    'meal_description' => $todayOverride->meal_description,
                    'reason' => $todayOverride->reason,
                    'is_override' => true,
                ];
            } elseif ($weeklyMenu) {
                $plannedItem = $weeklyMenu->items->firstWhere('day_of_week', $todayName);
                if ($plannedItem) { 
                    $todayMeal = [
                        'meal_description' => $plannedItem->meal_description,
                        'reason' => null,
                        'is_override' => false,
                    ];
                }
            }
        }

        return Inertia::render('CompanyAdmin/Dashboard', [
            'company' => $user->company,
            'tiffinService' => $tiffinService,
            'weeklyMenu' => $weeklyMenu,
            'todayOverride' => $todayOverride,
            'todayMeal' => $todayMeal,
        ]);
    }
}

