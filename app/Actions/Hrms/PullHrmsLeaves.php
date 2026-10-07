<?php

namespace App\Actions\Hrms;

use App\Jobs\ProcessHrmsLeaveEvent;
use App\Models\Company;
use App\Models\CompanyHrmsConnection;
use App\Models\HrmsPullRun;
use App\Models\HrmsWebhookEvent;
use App\Models\Skip;
use App\Services\Hrms\Adapters\CyberPulseAdapter;
use App\Services\Hrms\CyberPulse\CyberPulseClient;
use App\Services\Hrms\HrmsEventMapper;
use App\Services\Hrms\HrmsEventPlan;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

/**
 * One pull run for a company whose HRMS has no webhooks.
 *
 * It fetches, filters and shapes - and then hands every event to the same
 * pipeline an inbound webhook uses. It deliberately writes no skip of its own:
 * one set of guards, one audit trail, one dedupe rule and one health view,
 * whichever direction the event travelled.
 *
 * The dangerous half is cancellation. CyberPulse deletes a leave row outright
 * rather than marking it cancelled, so there is nothing to receive - the only
 * way to notice is to compare a fetch against what we applied before. A failed
 * or truncated fetch is indistinguishable from "every leave was cancelled", so
 * two guards stand in front of it: nothing is cancelled unless the fetch clearly
 * succeeded, and a run proposing to cancel more than a configured share of what
 * it holds cancels nothing and is marked suspicious for a human to look at.
 */
class PullHrmsLeaves
{
    public function __construct(
        protected CyberPulseClient $client,
        protected CyberPulseAdapter $adapter,
        protected HrmsEventMapper $mapper,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     dry_run: bool,
     *     status: string,
     *     error: ?string,
     *     fetched: int,
     *     approved_future: int,
     *     applied: int,
     *     duplicate: int,
     *     ignored: int,
     *     unknown_employee: int,
     *     unreadable: int,
     *     cancelled: int,
     *     cancel_candidates: int,
     *     held_leaves: int,
     *     unmatched: array<int, array{leave: string, employee_ref: ?string, employee_email: ?string}>,
     *     warnings: array<int, string>,
     *     details: array<int, array<string, mixed>>
     * }
     */
    public function execute(Company $company, bool $dryRun = false): array
    {
        $summary = [
            'ok' => false,
            'dry_run' => $dryRun,
            'status' => 'failed',
            'error' => null,
            'fetched' => 0,
            'approved_future' => 0,
            'applied' => 0,
            'duplicate' => 0,
            'ignored' => 0,
            'unknown_employee' => 0,
            'unreadable' => 0,
            'cancelled' => 0,
            'cancel_candidates' => 0,
            'held_leaves' => 0,
            // Who the HR system named that we could not place. Reported so an
            // admin can fix the mapping instead of only being told a number.
            // Flash and console only - never persisted, see finish().
            'unmatched' => [],
            'warnings' => [],
            'details' => [],
        ];

        $connection = CompanyHrmsConnection::where('company_id', $company->id)->first();

        if (! $connection || ! $connection->hasPullCredentials()) {
            $summary['error'] = 'No CyberPulse credentials are configured for this company.';

            return $this->finish($connection, $summary);
        }

        $fetch = $this->client->fetchLeaves($connection);

        if (! $fetch->ok) {
            $summary['error'] = $fetch->error;
            // Said explicitly, because "0 cancelled" on a failed run must not
            // read as "there was nothing to cancel".
            $summary['warnings'][] = 'Fetch failed, so nothing was cancelled.';

            return $this->finish($connection, $summary);
        }

        $summary['fetched'] = count($fetch->leaves);

        $timezone = $this->timezone($company);
        $approved = $this->approvedFutureLeaves($company, $fetch->leaves, $timezone);
        $summary['approved_future'] = count($approved);

        $this->applyApprovals($company, $approved, $timezone, $dryRun, $summary);
        $this->detectCancellations($company, $approved, $timezone, $dryRun, $summary);

        $summary['ok'] = true;
        $summary['status'] = $summary['warnings'] === [] ? 'ok' : 'suspicious';

        return $this->finish($connection, $summary);
    }

    /**
     * Approved leave that still has a day worth acting on.
     *
     * The status casing is inconsistent in the source - pending, Pending,
     * Approved, approved, Rejected all appear - so it is always lowercased before
     * comparing. Anything not approved is simply absent from this set, which is
     * also what makes an approved-then-rejected leave a cancellation candidate.
     *
     * Leaves ending before tomorrow are dropped: today's count is at or past its
     * cutoff, and a past day cannot be changed at all.
     *
     * @param  array<int, array<string, mixed>>  $leaves
     * @return array<string, array<string, mixed>> keyed by leave reference
     */
    protected function approvedFutureLeaves(Company $company, array $leaves, string $timezone): array
    {
        $tomorrow = Carbon::today($timezone)->addDay()->toDateString();
        $approved = [];

        foreach ($leaves as $leave) {
            if (strtolower(trim((string) ($leave['status'] ?? ''))) !== 'approved') {
                continue;
            }

            $leaveId = trim((string) ($leave['_id'] ?? ''));

            if ($leaveId === '') {
                continue;
            }

            $reference = $this->adapter->reference($leaveId);

            $event = $this->adapter->toEvent($leave, $timezone, $this->revisionFor($company, $reference));

            if ($event === null) {
                continue;
            }

            if (($event['leave']['to_date'] ?? '') < $tomorrow) {
                continue;
            }

            $approved[$reference] = $event;
        }

        return $approved;
    }

    /**
     * How many times this leave has already been cancelled.
     *
     * Derived from the events we recorded rather than kept in a counter column,
     * so it cannot drift from them: the number is exactly the number of
     * cancellations that actually got through, and a deduped one does not count.
     *
     * Matched on the id's own prefix instead of the event vocabulary, because
     * the ids are ours and the vocabulary is the vendor's.
     */
    protected function revisionFor(Company $company, string $reference): int
    {
        return HrmsWebhookEvent::where('company_id', $company->id)
            ->where('external_event_id', 'like', $reference.':cancelled%')
            ->count();
    }

    /**
     * @param  array<string, array<string, mixed>>  $approved
     * @param  array<string, mixed>  $summary
     */
    protected function applyApprovals(Company $company, array $approved, string $timezone, bool $dryRun, array &$summary): void
    {
        foreach ($approved as $reference => $event) {
            $plan = $this->mapper->map($company, $event);

            // A plan can be actionable in principle and still have nothing to
            // act on - a leave falling entirely on a weekend or a declared
            // holiday. ApplyHrmsLeaveEvent records that as ignored, so the same
            // judgement is made here: a dry run that reported it as "would
            // apply" would disagree with the real run it exists to predict.
            $nothingToDo = $plan->createDates === [] && $plan->releaseSkips()->isEmpty();

            $outcome = match ($plan->resolution) {
                HrmsEventPlan::APPLY => $nothingToDo ? 'ignored' : 'apply',
                HrmsEventPlan::IGNORED => 'ignored',
                HrmsEventPlan::UNKNOWN_EMPLOYEE => 'unknown_employee',
                default => 'unreadable',
            };

            $summary['details'][] = [
                'leave' => $reference,
                'action' => 'approve',
                'outcome' => $outcome,
                'dates' => $plan->createDates,
                'note' => $plan->note,
            ];

            match ($outcome) {
                'ignored' => $summary['ignored']++,
                'unknown_employee' => $summary['unknown_employee']++,
                'unreadable' => $summary['unreadable']++,
                default => null,
            };

            if ($outcome === 'unknown_employee') {
                $summary['unmatched'][] = [
                    'leave' => $reference,
                    'employee_ref' => $event['leave']['employee_id'] ?? null,
                    'employee_email' => $event['leave']['employee_email'] ?? null,
                ];
            }

            if ($dryRun) {
                // The same dedupe the real run gets from the unique index. A dry
                // run that skipped this check reported every already-applied
                // leave as "would apply" and never reported a duplicate, so the
                // numbers it printed were not the numbers a real run produced -
                // which is the one thing a dry run is for.
                if ($this->alreadyRecorded($company, $event['event_id'])) {
                    $summary['duplicate']++;

                    continue;
                }

                if ($outcome === 'apply') {
                    $summary['applied']++;
                }

                continue;
            }

            // Recorded and queued exactly as a webhook would be, so the event id
            // dedupes a re-run and the result lands on the health page.
            $recorded = $this->record($company, $event);

            if ($recorded === null) {
                $summary['duplicate']++;

                continue;
            }

            if ($outcome === 'apply') {
                $summary['applied']++;
            }
        }
    }

    /**
     * Leaves we applied that the source no longer reports as approved.
     *
     * @param  array<string, array<string, mixed>>  $approved
     * @param  array<string, mixed>  $summary
     */
    protected function detectCancellations(Company $company, array $approved, string $timezone, bool $dryRun, array &$summary): void
    {
        $held = $this->heldLeaveReferences($company, $timezone);
        $summary['held_leaves'] = count($held);

        $candidates = array_values(array_diff($held, array_keys($approved)));
        $summary['cancel_candidates'] = count($candidates);

        if ($candidates === []) {
            return;
        }

        $share = (float) config('hrms.cyberpulse.max_cancel_share', 0.3);
        $floor = (int) config('hrms.cyberpulse.cancels_always_allowed', 3);

        // The guard that matters: a partial or mis-scoped fetch looks exactly
        // like a mass cancellation, and wiping a day of skips silently adds
        // meals for people who are on leave.
        //
        // A share on its own cannot carry that, though. A company holding two
        // leaves is at 50% the moment one is withdrawn, so a pure percentage
        // would refuse every ordinary cancellation at small scale and the
        // integration would just stop releasing meals. The share is therefore
        // only consulted once there are more candidates than a handful - below
        // that a cancellation is routine, not an anomaly.
        if (count($candidates) > $floor && count($held) > 0 && (count($candidates) / count($held)) > $share) {
            $percent = round(count($candidates) / count($held) * 100);
            $limit = round($share * 100);

            $summary['warnings'][] = "Refused to cancel: {$percent}% of held leaves disappeared from the fetch, over the {$limit}% limit.";

            foreach ($candidates as $reference) {
                $summary['details'][] = [
                    'leave' => $reference,
                    'action' => 'cancel',
                    'outcome' => 'refused_by_guard',
                ];
            }

            return;
        }

        foreach ($candidates as $reference) {
            $event = $this->adapter->toCancellationEvent($reference, $this->revisionFor($company, $reference));

            $summary['details'][] = [
                'leave' => $reference,
                'action' => 'cancel',
                'outcome' => $dryRun ? 'would_cancel' : 'cancel',
            ];

            if ($dryRun) {
                if ($this->alreadyRecorded($company, $event['event_id'])) {
                    $summary['duplicate']++;

                    continue;
                }

                $summary['cancelled']++;

                continue;
            }

            if ($this->record($company, $event) !== null) {
                $summary['cancelled']++;
            } else {
                $summary['duplicate']++;
            }
        }
    }

    /**
     * Future-dated skips this integration created, by leave reference.
     *
     * Restricted to external_refs it owns, which is the guarantee for HR: a skip
     * entered by hand carries no external_ref, so it can never be a cancellation
     * candidate. The date floor matches the fetch filter, so a leave that has
     * simply passed is not mistaken for one that was withdrawn.
     *
     * @return array<int, string>
     */
    protected function heldLeaveReferences(Company $company, string $timezone): array
    {
        $tomorrow = Carbon::today($timezone)->addDay()->toDateString();

        return Skip::where('company_id', $company->id)
            ->whereNull('cancelled_at')
            ->whereIn('source', ['leave', 'wfh'])
            ->where('external_ref', 'like', 'cp:leave:%')
            ->where('date', '>=', $tomorrow)
            ->distinct()
            ->pluck('external_ref')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether this exact event state has been recorded before.
     *
     * Only consulted by a dry run: a real run learns the same thing from the
     * unique index when the insert is refused.
     */
    protected function alreadyRecorded(Company $company, string $eventId): bool
    {
        return HrmsWebhookEvent::where('company_id', $company->id)
            ->where('external_event_id', $eventId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function record(Company $company, array $event): ?HrmsWebhookEvent
    {
        try {
            $recorded = HrmsWebhookEvent::create([
                'company_id' => $company->id,
                'external_event_id' => $event['event_id'],
                'event_type' => $event['event_type'],
                'leave_external_id' => $event['leave']['id'] ?? null,
                'occurred_at' => Carbon::parse($event['occurred_at'])->toDateTimeString(),
                'payload' => $event,
                'status' => HrmsWebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (QueryException) {
            // unique(company_id, external_event_id): this state was already
            // recorded on an earlier run, so the run is idempotent for free.
            return null;
        }

        ProcessHrmsLeaveEvent::dispatch($recorded->id);

        return $recorded;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    protected function finish(?CompanyHrmsConnection $connection, array $summary): array
    {
        if ($connection && ! $summary['dry_run']) {
            // History, pruned after 30 days. Counts only - the per-leave detail
            // would reintroduce employee identifiers into a table that has no
            // need of them.
            HrmsPullRun::create([
                'company_id' => $connection->company_id,
                'adapter' => $connection->pull_adapter,
                'status' => $summary['status'],
                'dry_run' => false,
                'fetched' => $summary['fetched'],
                'applied' => $summary['applied'],
                'cancelled' => $summary['cancelled'],
                'ignored' => $summary['ignored'],
                'unknown_employee' => $summary['unknown_employee'],
                'duplicate' => $summary['duplicate'],
                'cancel_candidates' => $summary['cancel_candidates'],
                'warnings' => $summary['warnings'],
                'error' => $summary['error'],
            ]);

            $connection->forceFill([
                'last_pull_at' => now(),
                'last_pull_status' => $summary['status'],
                // Counts and warnings only. 'details' can run to hundreds of
                // rows, and 'unmatched' carries employee identifiers - neither
                // belongs in a column the health page reads.
                'last_pull_summary' => collect($summary)->except(['details', 'unmatched'])->all(),
                'last_pull_error' => $summary['error'],
            ])->save();
        }

        return $summary;
    }

    protected function timezone(Company $company): string
    {
        return $company->setting?->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');
    }
}
