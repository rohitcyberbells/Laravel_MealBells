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
     * One whitelisted attendance row as the only thing MealBells keeps: had
     * this person arrived by the time we had to order.
     *
     * The arrival time is read here and discarded here. Nothing downstream ever
     * sees it, which is why no column holds one - we need a decision, not a
     * record of anyone's movements.
     *
     * Returns null when the row cannot be judged at all: no employee
     * reference, or clocked_in true with an unreadable time. Null travels all
     * the way to the database as "we do not know", which the report is careful
     * never to count as an absence. That is the whole fail-safe: an unreadable
     * answer orders a meal.
     *
     * @param  array<string, mixed>  $row  a row from CyberPulseClient::ATTENDANCE_FIELDS
     * @return array{reference: string, email: ?string, clocked_in_by_cutoff: ?bool, is_wfh: bool}|null
     */
    public function toAttendance(array $row, string $date, string $timezone, string $cutoffTime): ?array
    {
        $reference = trim((string) ($row['employee_id'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));

        if ($reference === '' && $email === '') {
            return null;
        }

        $isWfh = (bool) ($row['is_wfh'] ?? false);

        // Absent is the one answer we can take at face value: the vendor is
        // telling us it has no clock-in for this person today.
        if (! ($row['clocked_in'] ?? false)) {
            return [
                'reference' => $reference,
                'email' => $email !== '' ? $email : null,
                'clocked_in_by_cutoff' => false,
                'is_wfh' => $isWfh,
            ];
        }

        $clockedInAt = $this->parseClockIn($row['clock_in_at'] ?? null, $timezone);

        if ($clockedInAt === null) {
            // They clocked in, but we cannot tell when - which could be the
            // encrypted value coming through unread. Unknown, not present and
            // not absent, so nobody loses a meal over a vendor bug.
            return [
                'reference' => $reference,
                'email' => $email !== '' ? $email : null,
                'clocked_in_by_cutoff' => null,
                'is_wfh' => $isWfh,
            ];
        }

        [$hour, $minute] = array_map('intval', array_pad(explode(':', $cutoffTime), 2, '0'));

        $cutoff = Carbon::createFromFormat('Y-m-d', $date, $timezone)->setTime($hour, $minute, 0);

        return [
            'reference' => $reference,
            'email' => $email !== '' ? $email : null,
            // On the cutoff minute counts as in time. Somebody clocking in at
            // exactly 11:00 against an 11:00 cutoff should not lose lunch to a
            // comparison operator.
            'clocked_in_by_cutoff' => $clockedInAt->lessThanOrEqualTo($cutoff),
            'is_wfh' => $isWfh,
        ];
    }

    /**
     * The vendor sends an ISO instant. Read in the company's timezone so the
     * comparison against the cutoff is like for like.
     *
     * Anything unparseable - including the `enc:` ciphertext the real model
     * stores, which comes through unread if the endpoint uses .lean() or an
     * aggregation - returns null rather than a guess.
     */
    protected function parseClockIn(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (str_starts_with($value, 'enc:')) {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
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
     * @param  int  $revision  how many times this leave has already been cancelled
     * @param  array<string, mixed>  $leave
     * @return array<string, mixed>|null
     */
    public function toEvent(array $leave, string $timezone, int $revision = 0): ?array
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
            'event_id' => $this->eventId($reference, 'approved', $revision),
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
     * @param  int  $revision  how many times this leave has already been cancelled
     * @return array<string, mixed>
     */
    public function toCancellationEvent(string $leaveReference, int $revision = 0): array
    {
        return [
            'event_id' => $this->eventId($leaveReference, 'cancelled', $revision),
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
     * The event id, which is also the idempotency key.
     *
     * Revision 0 keeps the documented form exactly - `cp:leave:{id}:approved` -
     * so the contract does not change for the ordinary case.
     *
     * A suffix only appears once a leave has been round-tripped. Without it, a
     * leave that is approved, withdrawn and then approved again would reuse the
     * id already consumed by the first approval: the second one would dedupe,
     * nothing would be applied, and the person's meal would be counted while
     * they were away. The counter is how many times the leave has been
     * cancelled, so each state in the cycle gets its own key.
     */
    public function eventId(string $leaveReference, string $state, int $revision = 0): string
    {
        return $revision > 0
            ? "{$leaveReference}:{$state}:r{$revision}"
            : "{$leaveReference}:{$state}";
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
