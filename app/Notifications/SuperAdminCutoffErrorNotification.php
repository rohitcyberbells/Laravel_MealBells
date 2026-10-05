<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SuperAdminCutoffErrorNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company,
        public string $date,
        public string $errorMessage
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'superadmin_cutoff_error',
            'title' => 'Cutoff Processing Error',
            'message' => "Cutoff processing failed for company {$this->company->name} on {$this->date}: {$this->errorMessage}",
            'company_name' => $this->company->name,
            'company_id' => $this->company->id,
            'date' => $this->date,
            'error' => $this->errorMessage,
        ];
    }
}
