<?php

namespace App\Notifications;

use App\Models\WeeklyMenu;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WeeklyMenuPublishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public WeeklyMenu $weeklyMenu) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification for database.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $tiffinName = $this->weeklyMenu->tiffinService?->name ?? 'Your Tiffin Service';

        return [
            'type' => 'weekly_menu_published',
            'title' => 'Weekly Menu Published',
            'message' => "{$tiffinName} has published the weekly menu starting {$this->weeklyMenu->week_start_date}.",
            'weekly_menu_id' => $this->weeklyMenu->id,
            'week_start_date' => $this->weeklyMenu->week_start_date,
        ];
    }
}
