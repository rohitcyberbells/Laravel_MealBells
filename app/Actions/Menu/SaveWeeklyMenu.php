<?php

namespace App\Actions\Menu;

use App\Events\WeeklyMenuPublished;
use App\Models\MenuItem;
use App\Models\User;
use App\Models\WeeklyMenu;

class SaveWeeklyMenu
{
    /**
     * Save weekly menu and items for a tiffin service.
     *
     * @param  array{week_start_date: string, status: string, items: array<int, array{day_of_week: string, meal_description: string}>}  $data
     */
    public function execute(User $user, array $data): WeeklyMenu
    {
        $menu = WeeklyMenu::updateOrCreate(
            [
                'tiffin_service_id' => $user->tiffin_service_id,
                'week_start_date' => $data['week_start_date'],
            ],
            [
                'status' => $data['status'],
            ]
        );

        foreach ($data['items'] as $itemData) {
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

        // Load items relation for fresh state
        $menu->load('items', 'tiffinService');

        // Dispatch notification event when menu is published
        if ($menu->status === 'published') {
            WeeklyMenuPublished::dispatch($menu);
        }

        return $menu;
    }
}
