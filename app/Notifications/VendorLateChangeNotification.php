<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The count changed after it was locked.
 *
 * This was in-app only, which is the wrong channel for it: by definition this
 * arrives after the vendor has already been told a number, often while they are
 * cooking, and nobody is watching a notification feed then.
 *
 * The mail leads with the new total rather than the delta. '+3 meals' requires
 * the reader to remember what it was; '53 meals, was 50' does not.
 */
class VendorLateChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $companyName,
        public string $date,
        public int $changeQuantity,
        public string $reason,
        public ?int $newTotal = null,
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
        $delta = $this->changeQuantity > 0 ? "+{$this->changeQuantity}" : (string) $this->changeQuantity;

        // 'UPDATED' first, because this lands in a thread that already has a
        // number in it and the reader is deciding whether to open it mid-shift.
        $subject = $this->newTotal !== null
            ? "[{$app}] UPDATED - {$this->companyName}: {$this->newTotal} meals for {$this->date}"
            : "[{$app}] UPDATED - {$this->companyName}: {$delta} meals for {$this->date}";

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('The count changed after it was locked')
            ->line("**{$this->companyName}** &mdash; {$this->date}");

        if ($this->newTotal !== null) {
            $previous = $this->newTotal - $this->changeQuantity;
            $mail->line("**New total: {$this->newTotal} meals** ({$delta}, was {$previous}).");
        } else {
            $mail->line("**Change: {$delta} meals.**");
        }

        return $mail
            ->line("Reason given: {$this->reason}")
            ->action('Open the preparation view', url('/tiffin-admin/preparation?date='.$this->date))
            ->salutation('— '.$app);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'vendor_late_change',
            'title' => 'Post-Cutoff Meal Change Logged',
            'message' => "Post-cutoff adjustment for {$this->companyName} on {$this->date}: {$this->changeQuantity} meals. Reason: {$this->reason}",
            'company_name' => $this->companyName,
            'date' => $this->date,
            'change_quantity' => $this->changeQuantity,
            'new_total' => $this->newTotal,
            'reason' => $this->reason,
        ];
    }
}
