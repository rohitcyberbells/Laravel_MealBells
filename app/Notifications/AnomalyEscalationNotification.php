<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AnomalyEscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company,
        public string $date,
        public array $anomalyFlags = []
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'anomaly_escalation',
            'title' => 'Unreviewed Count Anomaly Alert',
            'message' => "Anomalies detected for {$this->date} remain unreviewed: ".implode(', ', $this->anomalyFlags),
            'company_name' => $this->company->name,
            'date' => $this->date,
            'anomaly_flags' => $this->anomalyFlags,
        ];
    }
}
