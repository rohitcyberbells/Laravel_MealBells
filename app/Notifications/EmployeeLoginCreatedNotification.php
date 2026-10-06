<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hands an employee their own credentials.
 *
 * Queued, so provisioning a whole roster does not block the request. With the
 * sync queue in local and testing it still sends immediately.
 */
class EmployeeLoginCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company,
        public string $employeeCode,
        public string $temporaryPassword,
        public bool $isReset = false,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->isReset
            ? "Your {$this->company->name} meal portal password was reset"
            : "Your {$this->company->name} meal portal login";

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->isReset
                ? "Your password for the {$this->company->name} meal portal has been reset."
                : "A login has been created for you on the {$this->company->name} meal portal, where you can skip a meal you will not be having.")
            ->line('Temporary password: **'.$this->temporaryPassword.'**')
            ->action('Sign in', url('/login'))
            ->line("Sign in with this email address, or with company code {$this->company->code} and employee code {$this->employeeCode}.")
            ->line('You will be asked to choose your own password the first time you sign in.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'company_id' => $this->company->id,
            'employee_code' => $this->employeeCode,
            'is_reset' => $this->isReset,
        ];
    }
}
