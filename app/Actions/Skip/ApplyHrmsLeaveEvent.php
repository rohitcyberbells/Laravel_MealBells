<?php

namespace App\Actions\Skip;

use App\Actions\Meal\CancelSkip;
use App\Actions\Meal\RecordSkip;
use App\Enums\SkipOutcome;
use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HrmsWebhookEvent;
use App\Models\Skip;
use App\Services\Hrms\HrmsEventPlan;
use Carbon\Carbon;

/**
 * Executes one HrmsEventPlan.
 *
 * Every write goes through RecordSkip / CancelSkip, so the existing guard order
 * still runs and the HRMS gets no privileges the UI does not have.
 *
 * A guard refusal is an expected outcome here, not a failure: the day is logged
 * as blocked and the remaining days continue. Only genuinely transient errors
 * are allowed to escape, so that the queue retries those and nothing else.
 */
class ApplyHrmsLeaveEvent
{
    public function __construct(
        protected RecordSkip $recordSkip,
        protected CancelSkip $cancelSkip,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     applied_days: array<int, string>,
     *     already_days: array<int, string>,
     *     blocked_days: array<int, array{date: string, reason: string}>,
     *     released_days: array<int, string>,
     *     release_blocked: array<int, array{date: string, reason: string}>,
     *     non_meal_days: array<int, string>,
     *     outside_window_days: array<int, string>,
     *     notes: array<int, string>
     * }
     */
    public function execute(Company $company, HrmsEventPlan $plan): array
    {
        $result = [
            'status' => HrmsWebhookEvent::STATUS_APPLIED,
            'applied_days' => [],
            'already_days' => [],
            'blocked_days' => [],
            'released_days' => [],
            'release_blocked' => [],
            'non_meal_days' => $plan->nonMealDays,
            'outside_window_days' => $plan->outsideWindowDates,
            'notes' => array_filter([$plan->note]),
        ];

        if (! $plan->shouldApply()) {
            $result['status'] = $this->statusForUnactionablePlan($plan);

            return $result;
        }

        $this->backfillExternalId($company, $plan, $result);
        $this->createSkips($company, $plan, $result);
        $this->releaseSkips($company, $plan, $result);

        $result['status'] = $this->finalStatus($plan, $result);

        return $result;
    }

    /**
     * Record the vendor's own id on an employee matched by email.
     *
     * Email is the weakest key, so the first match on it is used to learn the
     * strong one and every later event for this person resolves on external_id.
     * Guarded on the column still being empty, which makes a repeat of the same
     * event a no-op and keeps an id already recorded from being overwritten.
     *
     * @param  array<string, mixed>  $result
     */
    protected function backfillExternalId(Company $company, HrmsEventPlan $plan, array &$result): void
    {
        if (! $plan->backfillExternalId || ! $plan->employee) {
            return;
        }

        // Scoped by company as well as id: an employee row is only ever this
        // company's to write.
        $written = Employee::where('id', $plan->employee->id)
            ->where('company_id', $company->id)
            ->where(fn ($query) => $query->whereNull('external_id')->orWhere('external_id', ''))
            ->update(['external_id' => $plan->backfillExternalId]);

        if ($written > 0) {
            $result['notes'][] = "Learned external_id '{$plan->backfillExternalId}' for {$plan->employee->employee_code} from an email match.";
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function createSkips(Company $company, HrmsEventPlan $plan, array &$result): void
    {
        if (! $plan->isApproval() || ! $plan->employee) {
            return;
        }

        foreach ($plan->createDates as $date) {
            try {
                $outcome = $this->recordSkip->execute(
                    $company,
                    $plan->employee,
                    $date,
                    $plan->source,
                    $plan->reason,
                    null,
                    $plan->leaveExternalId,
                )->outcome;

                match ($outcome) {
                    SkipOutcome::CREATED, SkipOutcome::REACTIVATED => $result['applied_days'][] = $date,

                    // First-source-wins: a skip is already in place, so the
                    // desired state holds and its original source is preserved.
                    SkipOutcome::ALREADY_SKIPPED => $result['already_days'][] = $date,

                    // Someone deliberately cancelled this skip. An auto source
                    // must not resurrect it, which is what keeps HR in charge.
                    SkipOutcome::BLOCKED_CANCELLED => $result['blocked_days'][] = [
                        'date' => $date,
                        'reason' => 'blocked_cancelled',
                    ],
                };
            } catch (MealRuleViolation $e) {
                // Past cutoff, locked count, inactive employee: permanent for
                // this day. Logged and skipped, never retried.
                $result['blocked_days'][] = [
                    'date' => $date,
                    'reason' => $e->getReasonCode()->value,
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function releaseSkips(Company $company, HrmsEventPlan $plan, array &$result): void
    {
        $skips = $plan->releaseSkips();

        if ($skips->isEmpty()) {
            return;
        }

        if (! $plan->actor) {
            // CancelSkip needs a real actor for the audit trail and we will not
            // invent one from another company.
            $result['notes'][] = 'Cannot release: company has no company_admin to attribute the change to.';

            foreach ($skips as $skip) {
                $result['release_blocked'][] = [
                    'date' => $this->dateOf($skip),
                    'reason' => 'no_actor',
                ];
            }

            return;
        }

        foreach ($skips as $skip) {
            try {
                // Marked as an integration release, so a later re-approval of
                // this same leave can restore it - while a skip a person
                // cancelled stays cancelled.
                $this->cancelSkip->execute($company, $skip, $plan->actor, 'hrms');
                $result['released_days'][] = $this->dateOf($skip);
            } catch (MealRuleViolation $e) {
                $result['release_blocked'][] = [
                    'date' => $this->dateOf($skip),
                    'reason' => $e->getReasonCode()->value,
                ];
            }
        }
    }

    protected function statusForUnactionablePlan(HrmsEventPlan $plan): string
    {
        return $plan->resolution === HrmsEventPlan::IGNORED
            ? HrmsWebhookEvent::STATUS_IGNORED
            : HrmsWebhookEvent::STATUS_BLOCKED;
    }

    /**
     * Applied when anything reached the desired state, blocked when everything
     * attempted was refused, and ignored when there was nothing to attempt -
     * a leave falling entirely on weekends, for instance.
     *
     * @param  array<string, mixed>  $result
     */
    protected function finalStatus(HrmsEventPlan $plan, array $result): string
    {
        $attempted = count($plan->createDates) + $plan->releaseSkips()->count();

        if ($attempted === 0) {
            return HrmsWebhookEvent::STATUS_IGNORED;
        }

        $succeeded = count($result['applied_days'])
            + count($result['already_days'])
            + count($result['released_days']);

        return $succeeded > 0
            ? HrmsWebhookEvent::STATUS_APPLIED
            : HrmsWebhookEvent::STATUS_BLOCKED;
    }

    protected function dateOf(Skip $skip): string
    {
        return Carbon::parse($skip->date)->toDateString();
    }
}
