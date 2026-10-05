<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class VendorLateChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $companyName,
        public string $date,
        public int $changeQuantity,
        public string $reason
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'vendor_late_change',
            'title' => 'Post-Cutoff Meal Change Logged',
            'message' => "Post-cutoff adjustment for {$this->companyName} on {$this->date}: {$this->changeQuantity} meals. Reason: {$this->reason}",
            'company_name' => $this->companyName,
            'date' => $this->date,
            'change_quantity' => $this->changeQuantity,
            'reason' => $this->reason,
        ];
    }
}
