<?php

namespace App\Actions\Meal;

use App\Events\DailyMealOverridden;
use App\Models\CompanyTiffinAssignment;
use App\Models\DailyOverrides;
use App\Models\User;
use Carbon\Carbon;

class CreateDailyOverride
{
    /**
     * Create or update daily meal override for today.
     *
     * @param  array{meal_description: string, reason?: string|null}  $data
     */
    public function execute(User $user, array $data): DailyOverrides
    {
        $tiffinId = $user->tiffin_service_id;

        $firstCompanySetting = $tiffinId ? CompanyTiffinAssignment::where('tiffin_service_id', $tiffinId)
            ->where('is_active', true)
            ->with('company.setting')
            ->first()?->company?->setting : null;

        $timezone = $firstCompanySetting?->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');
        $todayDate = Carbon::today($timezone)->toDateString();

        $override = DailyOverrides::updateOrCreate(
            [
                'tiffin_service_id' => $user->tiffin_service_id,
                'date' => $todayDate,
            ],
            [
                'meal_description' => $data['meal_description'],
                'reason' => $data['reason'] ?? null,
            ]
        );

        $override->load('tiffinService');

        DailyMealOverridden::dispatch($override);

        return $override;
    }
}
