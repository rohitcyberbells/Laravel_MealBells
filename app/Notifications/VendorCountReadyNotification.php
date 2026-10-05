<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class VendorCountReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $companyName,
        public string $date,
        public int $finalCount
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'vendor_count_ready',
            'title' => 'Daily Meal Count Ready',
            'message' => "Final meal count for {$this->companyName} on {$this->date} is confirmed at {$this->finalCount} meals.",
            'company_name' => $this->companyName,
            'date' => $this->date,
            'final_count' => $this->finalCount,
        ];
    }
}
