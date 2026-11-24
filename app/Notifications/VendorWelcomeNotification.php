<?php

namespace App\Notifications;

use App\Models\TiffinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The first thing a new vendor hears from us.
 *
 * Built, and off. Until config('mealbells.vendor_welcome_email') is turned on
 * nothing calls this - the super admin still reads the temporary password off
 * the screen and passes it on by hand, which is how every account has been
 * handed over so far.
 *
 * It exists now because the alternative is worse. The plaintext password is
 * shown exactly once and never stored; a closed tab means a reset. Whether
 * that is worth an email depends on how often it actually happens, which
 * nobody knows yet - so the thing is ready and the switch is a line of env.
 *
 * The password is deliberately NOT in here. Mail is not a safe place to put a
 * credential: it sits in two mailboxes and often a third server's logs. The
 * message says an account exists and where to sign in; the password still
 * travels the way the super admin chooses.
 */
class VendorWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TiffinService $tiffinService) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your MealBells kitchen account is ready')
            ->greeting("Hello {$notifiable->name},")
            ->line("An account has been created for {$this->tiffinService->name} on MealBells, where you will see how many meals to cook each morning.")
            ->line('Your temporary password was given to you separately. You will be asked to replace it the first time you sign in.')
            ->action('Sign in', url('/login'))
            ->line('If a company has not been paired with your kitchen yet, the counts page will say so — that is set up at our end and you will be told when it is live.');
    }
}
