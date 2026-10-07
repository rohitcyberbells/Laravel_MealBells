<?php

namespace App\Services\Hrms\Adapters;

use Carbon\Carbon;

/**
 * CyberPulse HRMS.
 *
 * Sits in the vendor seam so the mapper, guards, dedupe and health view stay
 * unaware of where an event came from. It only translates: vocabulary, field
 * paths and dates in, the canonical envelope out. It never decides whether a
 * skip happens - that is the mapper's and the engine's job.
 *
 * CyberPulse has no webhooks, so this is driven by hrms:pull rather than by an
 * inbound request. toGeneric() is still implemented for the seam's sake: if the
 * vendor ever posts a leave row to us, the same translation applies.
 */
class CyberPulseAdapter implements HrmsVendorAdapter
{
    /**
     * Vendor leave types onto the event names the mapper already understands.
     *
     * half-day and short-leave are deliberately mapped to a full-day leave event
     * carrying their own type string: the mapper's partial-day guard then records
     * them as ignored with a reason. That is better than dropping them here,
     * because the decision shows up on the health page instead of vanishing.
     */
    public const EVENT_FOR_TYPE = [
        'casual' => 'leave_approved',
        'sick' => 'leave_approved',
        'birthday' => 'leave_approved',
        'wfh' => 'wfh_approved',
        'half-day' => 'leave_approved',
        'short-leave' => 'leave_approved',
    ];

    public function name(): string
    {
        return 'cyberpulse';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function toGeneric(array $payload): array
    {
        // A posted body may be the leave row itself or wrapped in the same
        // envelope fetchAll uses.
        $leave = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return $this->toEvent($leave, config('mealbells.default_timezone', 'Asia/Kolkata')) ?? $payload;
    }

    /**
     * One whitelisted CyberPulse leave row as a canonical approval event.
     *
     * Returns null when the row cannot be turned into one - no id, no dates, or
     * a leave type we have not been taught. An unknown type is refused rather
     * than assumed to be full-day leave, because assuming wrongly cancels a meal
     * someone is going to eat.
     *
     * @param  array<string, mixed>  $leave
     * @return array<string, mixed>|null
     */
    public function toEvent(array $leave, string $timezone): ?array
    {
        $leaveId = $this->str($leave['_id'] ?? null);
        $type = strtolower((string) $this->str($leave['leaveType'] ?? null));
        $eventType = self::EVENT_FOR_TYPE[$type] ?? null;

        if ($leaveId === null || $eventType === null) {
            return null;
        }

        $from = $this->toCompanyDate($leave['startDate'] ?? null, $timezone);
        $to = $this->toCompanyDate($leave['endDate'] ?? null, $timezone) ?? $from;

        if ($from === null) {
            return null;
        }

        $reference = $this->reference($leaveId);
        $employee = is_array($leave['employeeId'] ?? null) ? $leave['employeeId'] : [];

        return [
            'event_id' => $reference.':approved',
            'event_type' => $eventType,
            // CyberPulse records no decision timestamp, so the moment we observed
            // the state stands in for one. It only has to increase between runs,
            // which is all the out-of-order check needs.
            'occurred_at' => now()->toIso8601String(),
            'leave' => [
                'id' => $reference,
                'employee_id' => $this->str($employee['_id'] ?? null) ?? '',
                'employee_email' => $this->str($employee['email'] ?? null),
                'from_date' => $from,
                'to_date' => $to,
                // Passed through so the partial-day guard can see it.
                'type' => $type,
                // Built from whitelisted data only. The vendor's own reason text
                // is never fetched - it can carry medical detail, and company
                // admins can read this.
                'reason' => "CyberPulse {$type} leave",
            ],
        ];
    }

    /**
     * The cancellation counterpart, for a leave we applied that the vendor no
     * longer reports as approved.
     *
     * @return array<string, mixed>
     */
    public function toCancellationEvent(string $leaveReference): array
    {
        return [
            'event_id' => $leaveReference.':cancelled',
            'event_type' => 'leave_cancelled',
            'occurred_at' => now()->toIso8601String(),
            // A cancellation needs nothing but the leave reference: the skips to
            // release are found through skips.external_ref.
            'leave' => ['id' => $leaveReference],
        ];
    }

    /**
     * Namespaced so a CyberPulse leave is identifiable wherever it is stored.
     *
     * This is what lands in skips.external_ref, which is how the pull later finds
     * its own skips - and only its own. A skip a person entered by hand has no
     * external_ref at all.
     */
    public function reference(string $leaveId): string
    {
        return "cp:leave:{$leaveId}";
    }

    /**
     * An ISO instant onto the calendar date it falls on in the company's zone.
     *
     * CyberPulse sends UTC. For an Asia/Kolkata company both 2026-10-08T00:00:00Z
     * and 2026-10-07T18:30:00Z are the 8th - the second is what IST-local midnight
     * looks like in UTC, and reading it naively puts the leave a day early.
     */
    protected function toCompanyDate(mixed $value, string $timezone): ?string
    {
        $raw = $this->str($value);

        if ($raw === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        try {
            return Carbon::parse($raw)->setTimezone($timezone)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function str(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
