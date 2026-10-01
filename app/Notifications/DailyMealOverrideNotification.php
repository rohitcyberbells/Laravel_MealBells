<?php

namespace App\Notifications;

use App\Models\DailyOverrides;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DailyMealOverrideNotification extends Notification
{
    use Queueable;

    public function __construct(public DailyOverrides $override) {}

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
        $tiffinName = $this->override->tiffinService?->name ?? 'Your Tiffin Service';

        return [
            'type' => 'daily_meal_override',
            'title' => "Today's Meal Updated",
            'message' => "{$tiffinName} updated today's meal to: {$this->override->meal_description}",
            'meal_description' => $this->override->meal_description,
            'reason' => $this->override->reason,
            'date' => $this->override->date,
        ];
    }
}
