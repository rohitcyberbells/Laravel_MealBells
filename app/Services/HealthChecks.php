<?php

namespace App\Services;

use App\Console\Commands\BackupDatabase;
use App\Models\CompanyHrmsConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One definition of "is this installation healthy".
 *
 * The monitoring endpoint and the alert mails must agree, or an operator ends
 * up with a green monitor and an inbox full of alerts and no way to tell which
 * is lying. Both read this.
 *
 * Every check is a cheap indexed query or a cache read: the endpoint is polled
 * once a minute from an unauthenticated position, and the alert command runs on
 * the same cron as the cutoff.
 */
class HealthChecks
{
    /**
     * The checks a monitor should page on. Keyed by issue name, which is also
     * the dedupe key for alerts, so renaming one re-alerts.
     *
     * @return array<string, array<string, mixed>>
     */
    public function operational(): array
    {
        return [
            'database' => $this->database(),
            'scheduler' => $this->scheduler(),
            'queue_failures' => $this->queueFailures(),
            'queue_worker' => $this->queueWorker(),
            'hrms_pull' => $this->hrmsPull(),
        ];
    }

    /**
     * Operational plus the backup, which is what gets alerted on.
     *
     * The backup is deliberately not in operational(): a backup 37 hours old is
     * a real problem but not an outage, and paging an uptime monitor at 3am for
     * it trains people to ignore the monitor.
     *
     * @return array<string, array<string, mixed>>
     */
    public function alertable(): array
    {
        return [...$this->operational(), 'backup' => $this->backup()];
    }

    /**
     * @return array<string, mixed>
     */
    public function database(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['ok' => true];
        } catch (Throwable $e) {
            // The class, not the message: a connection error carries the host,
            // the database name and sometimes the user.
            return ['ok' => false, 'detail' => 'query failed: '.class_basename($e)];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduler(): array
    {
        $staleAfter = (int) config('health.scheduler_stale_after_minutes', 3);
        $lastRun = Cache::get('scheduler_last_run');

        if (! $lastRun) {
            return [
                'ok' => false,
                'detail' => 'no heartbeat recorded; the cron entry may be missing',
                'stale_after_minutes' => $staleAfter,
            ];
        }

        $minutesAgo = (int) Carbon::createFromTimestamp($lastRun)->diffInMinutes(now());

        return [
            'ok' => $minutesAgo <= $staleAfter,
            'minutes_ago' => $minutesAgo,
            'stale_after_minutes' => $staleAfter,
            'detail' => $minutesAgo > $staleAfter
                ? "the last heartbeat was {$minutesAgo} minutes ago; nothing is locking counts"
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueFailures(): array
    {
        $threshold = (int) config('health.queue.failed_jobs_threshold', 25);
        $count = DB::table('failed_jobs')->count();

        return [
            'ok' => $count <= $threshold,
            'failed_jobs' => $count,
            'threshold' => $threshold,
            'detail' => $count > $threshold
                ? "{$count} failed jobs, over a threshold of {$threshold}"
                : null,
        ];
    }

    /**
     * Whether anything is draining the queue.
     *
     * There is no worker heartbeat to read, so this infers one from the work
     * itself: a job that has sat unreserved past the threshold means nothing
     * picked it up. An empty queue is reported as ok, because it is genuinely
     * no evidence either way - nobody should be paged because nothing happened
     * to be queued at 3am. The cost is that a worker which died with an empty
     * queue is not noticed until something is queued.
     *
     * @return array<string, mixed>
     */
    public function queueWorker(): array
    {
        $staleAfter = (int) config('health.queue.pending_job_stale_after_minutes', 10);

        try {
            $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        } catch (Throwable $e) {
            // A non-database queue driver has no jobs table to look at.
            return ['ok' => true, 'detail' => 'not measurable on this queue driver'];
        }

        if ($oldest === null) {
            return [
                'ok' => true,
                'detail' => 'queue empty, which is not evidence of a worker either way',
            ];
        }

        $waitingMinutes = (int) Carbon::createFromTimestamp($oldest)->diffInMinutes(now());

        return [
            'ok' => $waitingMinutes <= $staleAfter,
            'oldest_pending_job_minutes' => $waitingMinutes,
            'stale_after_minutes' => $staleAfter,
            'detail' => $waitingMinutes > $staleAfter
                ? "a job has waited {$waitingMinutes} minutes unreserved, so no worker is running; ".
                  'every notification is queued, so none are being delivered'
                : null,
        ];
    }

    /**
     * Only companies that poll an HRMS. A site with none is unaffected.
     *
     * 'suspicious' counts runs a safety guard stopped - a pull that tried to
     * cancel more meals than the share limit allows. That is reported as a
     * problem in its own right, because the guard holding means the leave was
     * not applied.
     *
     * @return array<string, mixed>
     */
    public function hrmsPull(): array
    {
        $staleAfter = (int) config('hrms.cyberpulse.pull_every_minutes', 15) * 3;

        $connections = CompanyHrmsConnection::whereNotNull('pull_base_url')
            ->get(['company_id', 'last_pull_at', 'last_pull_status', 'last_pull_summary']);

        if ($connections->isEmpty()) {
            return ['ok' => true, 'detail' => 'no pull connections configured'];
        }

        $stale = $connections->filter(
            fn (CompanyHrmsConnection $connection) => $connection->last_pull_at === null
                || $connection->last_pull_at->diffInMinutes(now()) > $staleAfter
        );

        $failed = $connections->filter(
            fn (CompanyHrmsConnection $connection) => $connection->last_pull_status === 'error'
        );

        $suspicious = $connections->filter(
            fn (CompanyHrmsConnection $connection) => ($connection->last_pull_summary['warnings'] ?? []) !== []
        );

        $problems = $stale->count() + $failed->count() + $suspicious->count();
        $fails = $problems > 0 && config('health.pull_staleness_fails', true);

        return [
            // Company ids, never names, base urls or credentials: the ping
            // response is readable by anyone holding the monitor's token.
            'ok' => ! $fails,
            'connections' => $connections->count(),
            'stale' => $stale->count(),
            'failed' => $failed->count(),
            'suspicious' => $suspicious->count(),
            'stale_after_minutes' => $staleAfter,
            'detail' => $problems > 0
                ? "{$stale->count()} stale, {$failed->count()} errored, {$suspicious->count()} stopped by a safety guard, ".
                  'out of '.$connections->count().' configured; approved leave may not be reaching the count'
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function backup(): array
    {
        $status = Cache::get(BackupDatabase::STATUS_KEY);
        $staleAfterHours = (int) config('backup.stale_after_hours', 36);

        if (! $status) {
            return [
                'ok' => false,
                'ever_run' => false,
                'detail' => 'no backup has ever been recorded; nothing here is recoverable without one',
            ];
        }

        $hoursAgo = (int) Carbon::parse($status['at'])->diffInHours(now());
        $isStale = $hoursAgo > $staleAfterHours;

        return [
            'ok' => (bool) $status['ok'] && ! $isStale,
            'ever_run' => true,
            'hours_ago' => $hoursAgo,
            'stale_after_hours' => $staleAfterHours,
            'detail' => match (true) {
                ! $status['ok'] => 'the last backup failed: '.($status['error'] ?? 'no reason recorded'),
                $isStale => "the last backup was {$hoursAgo} hours ago",
                default => null,
            },
        ];
    }
}
