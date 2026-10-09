<?php

namespace App\Services\Hrms;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Skip;
use App\Services\Hrms\Adapters\GenericHrmsAdapter;
use App\Services\Hrms\Adapters\HrmsVendorAdapter;
use App\Services\MealCalendar;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Turns one vendor webhook payload into an HrmsEventPlan.
 *
 * Read-only by design: it resolves the employee, translates the vocabulary and
 * works out which dates are in play, but never writes. Step 3 executes the plan
 * through RecordSkip / CancelSkip so the existing guards still run.
 *
 * Vendor differences live in config/hrms.php - 'payload_map' for field paths,
 * 'event_type_map' and 'type_map' for vocabulary - so a new HRMS is onboarded by
 * describing its JSON rather than by adding code here.
 */
class HrmsEventMapper
{
    public function __construct(protected HrmsActorResolver $actors) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(Company $company, array $payload): HrmsEventPlan
    {
        // Shape first, then field names: the adapter handles structures config
        // cannot describe, payload_map handles naming.
        $payload = $this->adapterFor($company)->toGeneric($payload);

        $paths = $this->paths($company);

        $rawEventType = trim((string) Arr::get($payload, $paths['event_type'], ''));
        $verb = $this->eventVerbs($company)[$rawEventType] ?? null;

        // An event we were not told how to classify is never guessed at: a wrong
        // guess either adds or removes someone's meal.
        if (! is_array($verb) || ! in_array($verb['action'] ?? null, ['approved', 'cancelled'], true)) {
            return HrmsEventPlan::unmappable("Unmapped event_type '{$rawEventType}'.");
        }

        $leaveExternalId = trim((string) Arr::get($payload, $paths['leave_id'], ''));

        if ($leaveExternalId === '') {
            return HrmsEventPlan::unmappable('Payload carries no leave id.');
        }

        $occurredAt = $this->parseTimestamp(Arr::get($payload, $paths['occurred_at']));

        return $verb['action'] === 'cancelled'
            ? $this->mapCancellation($company, $leaveExternalId, $occurredAt)
            : $this->mapApproval($company, $payload, $paths, $verb, $leaveExternalId, $occurredAt);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $paths
     * @param  array<string, mixed>  $verb
     */
    protected function mapApproval(
        Company $company,
        array $payload,
        array $paths,
        array $verb,
        string $leaveExternalId,
        ?Carbon $occurredAt,
    ): HrmsEventPlan {
        $rawLeaveType = $this->optionalString(Arr::get($payload, $paths['leave_type']));

        // Checked before the source, because the event name alone can say
        // 'leave_approved' while the leave type says it only covers half the day.
        if ($rawLeaveType !== null && $this->isPartialDay($company, $rawLeaveType)) {
            return HrmsEventPlan::ignored(
                "Partial-day leave is not supported (partial_day_not_supported): '{$rawLeaveType}'.",
                $leaveExternalId,
            );
        }

        $source = $verb['source'] ?? $this->sourceFromPayload($company, $payload, $paths);

        if (! in_array($source, ['leave', 'wfh'], true)) {
            $rawType = (string) Arr::get($payload, $paths['leave_type'], '');

            return HrmsEventPlan::unmappable("Unmapped leave type '{$rawType}'.");
        }

        // A company that has not opted into WFH auto-skip keeps eating on WFH
        // days. Same rule ValidateSkipCsv already enforces for CSV imports, so
        // both channels behave identically.
        if ($source === 'wfh' && ! (bool) ($company->setting?->wfh_auto_skip ?? false)) {
            return HrmsEventPlan::ignored('WFH auto-skip is disabled for this company.', $leaveExternalId);
        }

        $employeeReference = trim((string) Arr::get($payload, $paths['employee_ref'], ''));

        if ($employeeReference === '') {
            return HrmsEventPlan::unmappable('Payload carries no employee reference.');
        }

        $employeeEmail = $this->optionalString(Arr::get($payload, $paths['employee_email'] ?? ''));

        [$employee, $backfillExternalId] = $this->resolveEmployee($company, $employeeReference, $employeeEmail);

        if (! $employee) {
            return HrmsEventPlan::unknownEmployee($employeeReference, $leaveExternalId);
        }

        $fromDate = $this->normalizeDate($company, Arr::get($payload, $paths['from_date']));
        $toDate = $this->normalizeDate($company, Arr::get($payload, $paths['to_date'])) ?? $fromDate;

        if ($fromDate === null) {
            return HrmsEventPlan::unmappable('Approval carries no usable from_date.');
        }

        if ($toDate < $fromDate) {
            return HrmsEventPlan::unmappable('to_date is earlier than from_date.');
        }

        $expanded = $this->expandRange($company, $fromDate, $toDate);

        $reason = $this->optionalString(Arr::get($payload, $paths['reason']));

        return HrmsEventPlan::apply(
            action: 'approved',
            employee: $employee,
            leaveExternalId: $leaveExternalId,
            source: $source,
            reason: $reason,
            occurredAt: $occurredAt,
            createDates: $expanded['meal_days'],
            // A re-sent approval with a shorter range means the leave was edited.
            // Days it used to cover but no longer does must be released.
            releaseSkips: $this->skipsForLeave($company, $leaveExternalId, $expanded['meal_days']),
            nonMealDays: $expanded['non_meal_days'],
            outsideWindowDates: $expanded['outside_window'],
            actor: $this->actors->forCompany($company),
            backfillExternalId: $backfillExternalId,
        );
    }

    protected function mapCancellation(Company $company, string $leaveExternalId, ?Carbon $occurredAt): HrmsEventPlan
    {
        return HrmsEventPlan::apply(
            action: 'cancelled',
            employee: null,
            leaveExternalId: $leaveExternalId,
            source: null,
            reason: null,
            occurredAt: $occurredAt,
            createDates: [],
            releaseSkips: $this->skipsForLeave($company, $leaveExternalId, []),
            actor: $this->actors->forCompany($company),
        );
    }

    /**
     * Active skips this leave produced, minus any date it still covers.
     *
     * Matching on external_ref is what protects HR: a hand-entered skip has a
     * null external_ref, so it can never appear in this set and can never be
     * released by the HRMS.
     *
     * @param  array<int, string>  $keepDates
     * @return Collection<int, Skip>
     */
    protected function skipsForLeave(Company $company, string $leaveExternalId, array $keepDates): Collection
    {
        return Skip::where('company_id', $company->id)
            ->where('external_ref', $leaveExternalId)
            ->whereNull('cancelled_at')
            ->get()
            ->reject(fn (Skip $skip) => in_array(Carbon::parse($skip->date)->toDateString(), $keepDates, true))
            ->values();
    }

    /**
     * Split the range into the meal days actually in play, and the days dropped
     * for being a weekend/holiday or outside the editable window.
     *
     * The window is clamped to the engine's own advance limit, so the plan does
     * not list dates MealGuard is certain to reject - but every dropped day is
     * reported rather than silently lost.
     *
     * @return array{meal_days: array<int, string>, non_meal_days: array<int, string>, outside_window: array<int, string>}
     */
    protected function expandRange(Company $company, string $fromDate, string $toDate): array
    {
        $timezone = $this->timezone($company);
        $today = Carbon::today($timezone)->toDateString();
        $windowEnd = Carbon::today($timezone)
            ->addDays((int) config('mealbells.advance_limit_days', 60))
            ->toDateString();

        $mealDays = [];
        $nonMealDays = [];
        $outsideWindow = [];

        $cursor = Carbon::parse($fromDate);
        $end = Carbon::parse($toDate);

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $cursor->addDay();

            if ($date < $today || $date > $windowEnd) {
                $outsideWindow[] = $date;

                continue;
            }

            // MealCalendar also honours company_calendar_days, so a declared
            // holiday is dropped here just like a weekend.
            if (! MealCalendar::isMealDay($company, $date)) {
                $nonMealDays[] = $date;

                continue;
            }

            $mealDays[] = $date;
        }

        return [
            'meal_days' => $mealDays,
            'non_meal_days' => $nonMealDays,
            'outside_window' => $outsideWindow,
        ];
    }

    /**
     * Find an employee from a vendor reference, writing nothing.
     *
     * The same rules the leave mapper uses, exposed for the attendance pull so
     * there is one definition of how a vendor's handle becomes one of our
     * employees. Deliberately without the external_id backfill: shadow mode
     * reports and does not modify, so the attendance pull leaves the employee
     * record exactly as it found it.
     */
    public function findEmployee(Company $company, string $reference, ?string $email = null): ?Employee
    {
        return $this->resolveEmployee($company, $reference, $email)[0];
    }

    /**
     * external_id, then employee_code, then email. Every lookup is scoped to the
     * company, so a vendor id belonging to another tenant cannot resolve here.
     *
     * Email is last because it is the weakest key: people change address and two
     * systems disagree about it, whereas external_id is the vendor's own handle.
     * An email match therefore reports the vendor id back, so the next event for
     * this person resolves on the strong key instead.
     *
     * @return array{0: ?Employee, 1: ?string} the employee, and the vendor id to
     *                                         write onto it when the match was by email
     */
    protected function resolveEmployee(Company $company, string $reference, ?string $email = null): array
    {
        $byExternalId = Employee::where('company_id', $company->id)
            ->where('external_id', $reference)
            ->first();

        if ($byExternalId) {
            return [$byExternalId, null];
        }

        $byCode = Employee::where('company_id', $company->id)
            ->whereRaw('UPPER(employee_code) = ?', [strtoupper(trim($reference))])
            ->first();

        if ($byCode) {
            return [$byCode, null];
        }

        // The reference itself can be an address, for a vendor that identifies
        // people that way; an explicit email field wins when both are present.
        $candidate = $email ?? (str_contains($reference, '@') ? $reference : null);

        if ($candidate === null) {
            return [null, null];
        }

        $byEmail = Employee::where('company_id', $company->id)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($candidate))])
            ->first();

        if (! $byEmail) {
            return [null, null];
        }

        // Only when empty, so a vendor id already recorded is never overwritten
        // by a later event - and a repeat of the same event writes nothing.
        $backfill = ($byEmail->external_id === null || trim((string) $byEmail->external_id) === '')
            && $reference !== ''
            && ! str_contains($reference, '@')
                ? $reference
                : null;

        return [$byEmail, $backfill];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $paths
     */
    protected function sourceFromPayload(Company $company, array $payload, array $paths): ?string
    {
        $rawType = trim((string) Arr::get($payload, $paths['leave_type'], ''));

        return $this->typeMap($company)[$rawType] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function paths(Company $company): array
    {
        return array_merge(
            config('hrms.payload_defaults', []),
            config("hrms.companies.{$company->id}.payload_map") ?? []
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function eventVerbs(Company $company): array
    {
        return array_merge(
            config('hrms.event_type_defaults', []),
            config("hrms.companies.{$company->id}.event_type_map") ?? []
        );
    }

    /**
     * A leave type that covers part of a day rather than all of it.
     */
    protected function isPartialDay(Company $company, string $rawLeaveType): bool
    {
        $types = array_merge(
            config('hrms.partial_day_defaults', []),
            config("hrms.companies.{$company->id}.partial_day_types") ?? []
        );

        $needle = strtolower(trim($rawLeaveType));

        foreach ($types as $type) {
            if (strtolower(trim((string) $type)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function typeMap(Company $company): array
    {
        return array_merge(
            config('hrms.type_defaults', []),
            config("hrms.companies.{$company->id}.type_map") ?? []
        );
    }

    protected function adapterFor(Company $company): HrmsVendorAdapter
    {
        $class = config("hrms.companies.{$company->id}.adapter")
            ?? config('hrms.default_adapter', GenericHrmsAdapter::class);

        return app($class);
    }

    protected function timezone(Company $company): string
    {
        return $company->setting?->timezone ?? config('mealbells.default_timezone', 'Asia/Kolkata');
    }

    /**
     * A plain 'Y-m-d' is taken at face value. A value carrying a time is read in
     * the vendor's timezone and converted first, so 18:30 UTC does not land on
     * the wrong IST day.
     */
    protected function normalizeDate(Company $company, mixed $value): ?string
    {
        $value = $this->optionalString($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        $companyTimezone = $this->timezone($company);
        $vendorTimezone = config("hrms.companies.{$company->id}.timezone") ?? $companyTimezone;

        try {
            return Carbon::parse($value, $vendorTimezone)->setTimezone($companyTimezone)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function parseTimestamp(mixed $value): ?Carbon
    {
        $value = $this->optionalString($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function optionalString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
