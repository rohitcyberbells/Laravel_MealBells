<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\HealthIssueNotification;
use App\Notifications\HealthResolvedNotification;
use App\Services\HealthChecks;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Mails whoever operates this installation when a health check fails.
 *
 * Before this, nothing alerted: the health page had to be opened by a person,
 * and the failures that cost the most - the scheduler dead, the worker stopped,
 * a pull that silently stopped - all look exactly like a quiet day.
 *
 * Runs on the same cron as everything else, every five minutes.
 */
class SendHealthAlerts extends Command
{
    protected $signature = 'mealbells:health-alerts {--dry-run : Report what would be sent without sending it}';

    protected $description = 'Email the operators about failing health checks, and about ones that have cleared';

    /**
     * One cache entry per issue, holding when it was first seen failing and
     * when it was last mailed.
     *
     * Deliberately in the cache rather than a table: this is state about the
     * installation, not a record anyone needs to keep, and losing it on a cache
     * flush costs at most one duplicate alert.
     */
    protected const STATE_KEY_PREFIX = 'health_alert:';

    /**
     * Longer than any plausible gap between runs, so an issue that persists
     * does not forget it was already reported.
     */
    protected const STATE_TTL_DAYS = 30;

    public function handle(HealthChecks $checks): int
    {
        if (! config('health.alerts.enabled', true)) {
            $this->comment('Health alerts are disabled (HEALTH_ALERTS_ENABLED).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $recipients = $this->recipients();

        if ($recipients === [] && ! $dryRun) {
            // Said out loud rather than passed over: silent alerting is worse
            // than none, because it is believed.
            $this->error('No alert recipients. Set HEALTH_ALERT_RECIPIENTS or create a super admin.');

            return self::FAILURE;
        }

        $repeatAfter = (int) config('health.alerts.repeat_after_minutes', 60);
        $sent = 0;
        $resolved = 0;

        foreach ($checks->alertable() as $issue => $result) {
            $key = self::STATE_KEY_PREFIX.$issue;
            $state = Cache::get($key);

            if ($result['ok'] === true) {
                if ($state === null) {
                    continue;
                }

                $minutesFailing = (int) max(0, now()->diffInMinutes(
                    Carbon::createFromTimestamp($state['first_seen']),
                    absolute: true,
                ));

                $this->line("resolved: {$issue} (was failing ~{$minutesFailing}m)");

                if (! $dryRun) {
                    Cache::forget($key);
                    Notification::send($recipients, new HealthResolvedNotification($issue, $minutesFailing));
                }

                $resolved++;

                continue;
            }

            $firstSeen = $state['first_seen'] ?? now()->timestamp;
            $lastSent = $state['last_sent'] ?? null;

            $isDue = $lastSent === null
                || Carbon::createFromTimestamp($lastSent)->diffInMinutes(now()) >= $repeatAfter;

            if (! $isDue) {
                $this->line("holding: {$issue} (already mailed, next after {$repeatAfter}m)");

                continue;
            }

            $this->warn("alerting: {$issue} - ".($result['detail'] ?? 'failing'));

            if (! $dryRun) {
                Notification::send($recipients, new HealthIssueNotification($issue, $result));

                Cache::put($key, [
                    'first_seen' => $firstSeen,
                    'last_sent' => now()->timestamp,
                ], now()->addDays(self::STATE_TTL_DAYS));
            }

            $sent++;
        }

        if ($sent === 0 && $resolved === 0) {
            $this->info('All health checks passing, or already reported.');
        }

        return self::SUCCESS;
    }

    /**
     * Configured addresses if there are any, otherwise every active super
     * admin.
     *
     * A deactivated account is skipped: it cannot sign in to act on the alert,
     * and in several cases the address belongs to someone who has left.
     *
     * @return array<int, mixed>
     */
    protected function recipients(): array
    {
        $configured = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('health.alerts.recipients', '')),
        )));

        if ($configured !== []) {
            return array_map(
                fn (string $address) => Notification::route('mail', $address),
                $configured,
            );
        }

        return User::where('role', 'super_admin')
            ->where('is_active', true)
            ->get()
            ->all();
    }
}
