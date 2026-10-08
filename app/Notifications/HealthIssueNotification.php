<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One failing health check, mailed to whoever operates this installation.
 *
 * Written to be actionable from a phone at night: what broke, what it costs
 * while it stays broken, and the one command that tells you more. An alert that
 * only says "queue_worker: false" sends someone to a laptop to find out whether
 * it matters.
 */
class HealthIssueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * What each failure actually costs, and what to do. Keyed by issue name.
     *
     * @var array<string, array{consequence: string, action: string}>
     */
    protected const GUIDANCE = [
        'database' => [
            'consequence' => 'The application is down. Nothing works.',
            'action' => 'Check the database server is up and reachable from the web host.',
        ],
        'scheduler' => [
            'consequence' => 'No count will lock and no vendor will be told how many meals to cook. '.
                'This is the failure that costs the most.',
            'action' => 'Check the one cron entry: `* * * * * cd /path && php artisan schedule:run`. '.
                'Run `php artisan schedule:run` by hand to see if it errors.',
        ],
        'queue_failures' => [
            'consequence' => 'Each failed job is work that did not happen - most often a notification '.
                'nobody received.',
            'action' => 'Run `php artisan queue:failed` to see what they are. Retry with '.
                '`php artisan queue:retry all` once you know why they failed.',
        ],
        'queue_worker' => [
            'consequence' => 'Every notification in MealBells is queued, so none are being delivered - '.
                'not even the in-app ones. Vendors are not being told their counts.',
            'action' => 'Run `supervisorctl status mealbells-worker`, then `supervisorctl start '.
                'mealbells-worker`. Check /var/log/mealbells-worker.log for why it stopped.',
        ],
        'hrms_pull' => [
            'consequence' => 'Approved leave is not reaching the count, so people on leave are being '.
                'counted for meals. It looks exactly like a quiet day with no leave.',
            'action' => 'Open the health page for the per-company detail, then run '.
                '`php artisan hrms:pull {company} --dry-run` to see the error without changing anything.',
        ],
        'backup' => [
            'consequence' => 'Nothing in MealBells is recoverable without a backup.',
            'action' => 'Run `php artisan mealbells:backup` and read the output. Check the disk has '.
                'space and that BACKUP_DIRECTORY is writable.',
        ],
    ];

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $issue,
        public array $result,
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
        $guidance = self::GUIDANCE[$this->issue] ?? null;
        $app = config('app.name', 'MealBells');

        $mail = (new MailMessage)
            ->error()
            ->subject("[{$app}] ".$this->title().' needs attention')
            ->line($this->detail());

        if ($guidance) {
            $mail->line('**What this costs while it lasts:** '.$guidance['consequence'])
                ->line('**What to check:** '.$guidance['action']);
        }

        $repeat = (int) config('health.alerts.repeat_after_minutes', 60);

        return $mail
            ->line("This will not be mailed again for {$repeat} minutes, and you will get one message when it clears.")
            ->salutation('— '.$app);
    }

    public function title(): string
    {
        return match ($this->issue) {
            'database' => 'The database',
            'scheduler' => 'The scheduler',
            'queue_failures' => 'Failed jobs',
            'queue_worker' => 'The queue worker',
            'hrms_pull' => 'HRMS leave pulls',
            'backup' => 'Backups',
            default => ucfirst(str_replace('_', ' ', $this->issue)),
        };
    }

    public function detail(): string
    {
        return (string) ($this->result['detail'] ?? 'This check is failing. See the health page for detail.');
    }
}
