<?php

namespace App\Actions\Meal;

use App\Events\DailyMealOverridden;
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
        $override = DailyOverrides::updateOrCreate(
            [
                'tiffin_service_id' => $user->tiffin_service_id,
                'date' => Carbon::today()->toDateString(),
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
