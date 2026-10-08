<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An issue that has cleared.
 *
 * Sent because the alternative is worse: an alert with no closing message
 * leaves the reader unable to tell a fixed problem from one nobody has looked
 * at, so they either check manually every time or stop trusting the alerts.
 */
class HealthResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $issue,
        public int $minutesFailing,
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
        $app = config('app.name', 'MealBells');
        $title = (new HealthIssueNotification($this->issue, []))->title();

        $mail = (new MailMessage)
            ->success()
            ->subject("[{$app}] ".$title.' is back to normal')
            ->line($title.' is passing again.');

        if ($this->minutesFailing > 0) {
            $mail->line("It was failing for about {$this->minutesFailing} minutes.");
        }

        // Said plainly, because a recovery mail is not the same as a repair:
        // the scheduler catching up does not retroactively lock yesterday's
        // count, and a worker restarting does not resend what was dropped.
        return $mail
            ->line('Whatever did not happen while it was failing has not been made up for automatically. '.
                'Worth a look at the health page.')
            ->salutation('— '.$app);
    }
}
