<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Cutoff processing failed for a company.
 *
 * This was in-app only, which made it the least useful place it could be: the
 * count did not lock, so the vendor was never told how many meals to cook, and
 * the only sign of it sat in a feed nobody opens at 11am. Mailed now as well.
 */
class SuperAdminCutoffErrorNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company,
        public string $date,
        public string $errorMessage
    ) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return $notifiable->canReceiveMail()
            ? ['database', 'mail']
            : ['database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $app = config('app.name', 'MealBells');

        return (new MailMessage)
            ->error()
            ->subject("[{$app}] Cutoff failed for {$this->company->name} on {$this->date}")
            ->greeting('A cutoff did not complete')
            ->line("**{$this->company->name}** &mdash; {$this->date}")
            ->line("Error: {$this->errorMessage}")
            // Said explicitly, because the consequence is not obvious from the
            // error: a cutoff that failed means no locked count, which means
            // the vendor was never told a number for this day.
            ->line('**While this is unresolved there is no locked count for that day, so the vendor '.
                'has not been told how many meals to cook.** The scheduler retries every minute, so a '.
                'transient fault clears itself; a repeating one does not.')
            ->line('This is sent once per company per day, so it will not repeat for the same failure.')
            ->action('Open the health page', url('/super-admin/health'))
            ->salutation('— '.$app);
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
