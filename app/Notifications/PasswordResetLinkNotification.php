<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The reset link, written for the person receiving it rather than in the
 * framework's default wording.
 *
 * It names no account detail beyond the address it was sent to: the request is
 * answered identically whether or not an account exists, so this mail is the
 * only thing that confirms one does - and it only ever reaches the address that
 * already owned it.
 */
class PasswordResetLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your MealBells password')
            ->greeting("Hello {$notifiable->name},")
            ->line('Someone asked to reset the password for this MealBells account.')
            ->action('Choose a new password', url(route('password.reset', [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], absolute: false)))
            ->line("This link stops working in {$minutes} minutes, and can only be used once.")
            ->line('If this was not you, nothing has changed and you can ignore this message.');
    }
}
