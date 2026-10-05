<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class DailyCountSummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company,
        public string $date,
        public int $finalCount,
        public array $anomalyFlags = []
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'daily_count_summary',
            'title' => 'Daily Meal Count Summary',
            'message' => "Expected count for {$this->date}: {$this->finalCount} meals.",
            'company_name' => $this->company->name,
            'date' => $this->date,
            'final_count' => $this->finalCount,
            'anomaly_flags' => $this->anomalyFlags,
        ];
    }
}
