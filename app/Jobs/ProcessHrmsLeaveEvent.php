<?php

namespace App\Jobs;

use App\Actions\Skip\ApplyHrmsLeaveEvent;
use App\Models\HrmsWebhookEvent;
use App\Services\Hrms\HrmsEventMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Does the actual work for one received webhook event.
 *
 * The controller only records and dispatches, because vendors time out after a
 * few seconds and retry anything slow.
 *
 * Retries are reserved for transient failures. A guard refusal or an unknown
 * employee is permanent, so it is recorded as blocked and the job completes
 * successfully - retrying it three times would only produce noise.
 */
class ProcessHrmsLeaveEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public int $eventId) {}

    public function handle(HrmsEventMapper $mapper, ApplyHrmsLeaveEvent $apply): void
    {
        $event = HrmsWebhookEvent::with('company.setting')->find($this->eventId);

        // 'failed' is included so a retry can pick the event back up; any other
        // status means it already reached a final state.
        if (! $event || ! in_array($event->status, [
            HrmsWebhookEvent::STATUS_RECEIVED,
            HrmsWebhookEvent::STATUS_FAILED,
        ], true)) {
            return;
        }

        $company = $event->company;

        if (! $company) {
            $this->finish($event, HrmsWebhookEvent::STATUS_BLOCKED, ['notes' => ['Company no longer exists.']]);

            return;
        }

        if ($this->isSuperseded($event)) {
            $this->finish($event, HrmsWebhookEvent::STATUS_STALE, [
                'notes' => ['A newer applied event for this leave already superseded it.'],
            ]);

            return;
        }

        try {
            $plan = $mapper->map($company, $event->payload ?? []);
            $result = $apply->execute($company, $plan);

            $this->finish($event, $result['status'], $result);
        } catch (Throwable $e) {
            // Nothing expected reaches here: guard refusals are handled per day
            // inside ApplyHrmsLeaveEvent. So this is transient, and rethrowing
            // hands it back to the queue.
            $event->update([
                'status' => HrmsWebhookEvent::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            Log::error("HRMS event {$event->external_event_id} failed for company {$company->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * Webhook delivery is not ordered. An approval that arrives behind a newer
     * cancellation for the same leave must not re-create the skip, so it is
     * parked as stale instead.
     *
     * Any newer event we already reached a decision on counts, not just an
     * applied one. A cancellation that found nothing to release is recorded as
     * ignored, and a newer cancellation a guard refused is recorded as blocked -
     * in both cases the newer truth about this leave is that it is cancelled,
     * and acting on the older approval would be worse than doing nothing.
     *
     * 'stale' and 'failed' are excluded: neither represents a decision, and a
     * failed event is still due a retry.
     */
    protected function isSuperseded(HrmsWebhookEvent $event): bool
    {
        if (! $event->occurred_at || ! $event->leave_external_id) {
            return false;
        }

        return HrmsWebhookEvent::where('company_id', $event->company_id)
            ->where('leave_external_id', $event->leave_external_id)
            ->where('id', '!=', $event->id)
            ->whereIn('status', [
                HrmsWebhookEvent::STATUS_APPLIED,
                HrmsWebhookEvent::STATUS_BLOCKED,
                HrmsWebhookEvent::STATUS_IGNORED,
            ])
            ->where('occurred_at', '>', $event->occurred_at)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function finish(HrmsWebhookEvent $event, string $status, array $result): void
    {
        $event->update([
            'status' => $status,
            'result' => $result,
            'error' => null,
            'processed_at' => now(),
        ]);
    }

    /**
     * Reached once the queue gives up, so the row does not sit at 'received'.
     */
    public function failed(Throwable $e): void
    {
        HrmsWebhookEvent::where('id', $this->eventId)->update([
            'status' => HrmsWebhookEvent::STATUS_FAILED,
            'error' => $e->getMessage(),
            'processed_at' => now(),
        ]);
    }
}
