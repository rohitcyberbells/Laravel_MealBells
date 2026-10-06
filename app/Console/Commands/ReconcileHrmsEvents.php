<?php

namespace App\Console\Commands;

use App\Jobs\ProcessHrmsLeaveEvent;
use App\Models\HrmsWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Backstop for events that never finished.
 *
 * A delivery is only acknowledged once it is recorded, so nothing is lost at the
 * edge - but a job can still be dropped by a worker restart, or fail on a
 * transient error. This picks both cases back up.
 *
 * Safe to run repeatedly: the job's own status guard and RecordSkip's
 * first-source-wins rule make re-processing a no-op rather than a second skip.
 */
class ReconcileHrmsEvents extends Command
{
    protected $signature = 'hrms:reconcile {--dry-run : List what would be re-dispatched without queueing anything}';

    protected $description = 'Re-dispatch HRMS webhook events stuck in received, and retry failed ones';

    public function handle(): int
    {
        Cache::put('hrms_reconcile_last_run', now()->timestamp);

        $dryRun = (bool) $this->option('dry-run');
        $stuck = $this->stuckEvents();
        $retryable = $this->retryableEvents();

        if ($stuck->isEmpty() && $retryable->isEmpty()) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        foreach ($stuck as $event) {
            $this->redispatch($event, 'stuck in received', $dryRun);
        }

        foreach ($retryable as $event) {
            $this->redispatch($event, "failed, attempt {$event->reconcile_attempts}", $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * Accepted but never processed: the job was lost before it ran.
     *
     * Deliberately uncapped. The job has not run even once, so abandoning these
     * would mean a stopped queue worker silently loses leave - the stuck count on
     * the health page is the signal for that, not a dropped event.
     *
     * @return Collection<int, HrmsWebhookEvent>
     */
    protected function stuckEvents()
    {
        return HrmsWebhookEvent::where('status', HrmsWebhookEvent::STATUS_RECEIVED)
            ->where('created_at', '<=', now()->subMinutes($this->staleMinutes()))
            ->where(fn ($q) => $q->whereNull('last_reconciled_at')
                ->orWhere('last_reconciled_at', '<=', now()->subMinutes($this->staleMinutes())))
            ->orderBy('id')
            ->get();
    }

    /**
     * Transient failures, capped: after the configured number of passes the
     * event is left alone for a human. Blocked, ignored and stale events are
     * never retried, because none of them is going to change on its own.
     *
     * @return Collection<int, HrmsWebhookEvent>
     */
    protected function retryableEvents()
    {
        return HrmsWebhookEvent::where('status', HrmsWebhookEvent::STATUS_FAILED)
            ->where('reconcile_attempts', '<', $this->maxAttempts())
            ->where(fn ($q) => $q->whereNull('last_reconciled_at')
                ->orWhere('last_reconciled_at', '<=', now()->subMinutes($this->staleMinutes())))
            ->orderBy('id')
            ->get();
    }

    protected function redispatch(HrmsWebhookEvent $event, string $why, bool $dryRun): void
    {
        $this->line(($dryRun ? '[dry-run] ' : '')."Event {$event->external_event_id} (company {$event->company_id}): {$why}");

        if ($dryRun) {
            return;
        }

        // Stamped before dispatching, so a job that dies again still leaves the
        // attempt recorded and the cooldown in place.
        $event->update([
            'reconcile_attempts' => $event->reconcile_attempts + 1,
            'last_reconciled_at' => now(),
        ]);

        ProcessHrmsLeaveEvent::dispatch($event->id);
    }

    protected function staleMinutes(): int
    {
        return (int) config('hrms.reconcile.stale_after_minutes', 10);
    }

    protected function maxAttempts(): int
    {
        return (int) config('hrms.reconcile.max_attempts', 3);
    }
}
