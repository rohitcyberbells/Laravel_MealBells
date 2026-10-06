<?php

namespace App\Services\Hrms;

use App\Models\Employee;
use App\Models\Skip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What the mapper decided about one inbound event. Pure data: nothing here has
 * touched a skip. Step 3 reads this and performs the writes.
 *
 * $resolution tells Step 3 how to record the event:
 *   apply            -> act on it
 *   unknown_employee -> blocked (permanent: retrying cannot find the employee)
 *   ignored          -> blocked (deliberately not actioned, e.g. WFH disabled)
 *   unmappable       -> blocked (payload cannot be understood)
 */
class HrmsEventPlan
{
    public const APPLY = 'apply';

    public const UNKNOWN_EMPLOYEE = 'unknown_employee';

    public const IGNORED = 'ignored';

    public const UNMAPPABLE = 'unmappable';

    /**
     * @param  array<int, string>  $createDates  meal days the leave now covers
     * @param  Collection<int, Skip>  $releaseSkips  skips this leave created that it no longer covers
     * @param  array<int, string>  $nonMealDays  days dropped as weekend or holiday
     * @param  array<int, string>  $outsideWindowDates  days dropped as past or beyond the advance limit
     */
    public function __construct(
        public readonly string $resolution,
        public readonly ?string $action = null,
        public readonly ?Employee $employee = null,
        public readonly ?string $leaveExternalId = null,
        public readonly ?string $source = null,
        public readonly ?string $reason = null,
        public readonly ?Carbon $occurredAt = null,
        public readonly array $createDates = [],
        public readonly ?Collection $releaseSkips = null,
        public readonly array $nonMealDays = [],
        public readonly array $outsideWindowDates = [],
        public readonly ?User $actor = null,
        public readonly ?string $note = null,
    ) {}

    /**
     * @return Collection<int, Skip>
     */
    public function releaseSkips(): Collection
    {
        return $this->releaseSkips ?? new Collection;
    }

    /**
     * @param  array<int, string>  $createDates
     * @param  Collection<int, Skip>  $releaseSkips
     * @param  array<int, string>  $nonMealDays
     * @param  array<int, string>  $outsideWindowDates
     */
    public static function apply(
        string $action,
        ?Employee $employee,
        string $leaveExternalId,
        ?string $source,
        ?string $reason,
        ?Carbon $occurredAt,
        array $createDates,
        Collection $releaseSkips,
        array $nonMealDays = [],
        array $outsideWindowDates = [],
        ?User $actor = null,
    ): self {
        return new self(
            resolution: self::APPLY,
            action: $action,
            employee: $employee,
            leaveExternalId: $leaveExternalId,
            source: $source,
            reason: $reason,
            occurredAt: $occurredAt,
            createDates: $createDates,
            releaseSkips: $releaseSkips,
            nonMealDays: $nonMealDays,
            outsideWindowDates: $outsideWindowDates,
            actor: $actor,
        );
    }

    public static function unknownEmployee(string $employeeReference, ?string $leaveExternalId = null): self
    {
        return new self(
            resolution: self::UNKNOWN_EMPLOYEE,
            leaveExternalId: $leaveExternalId,
            note: "No employee in this company matches '{$employeeReference}'.",
        );
    }

    public static function ignored(string $note, ?string $leaveExternalId = null): self
    {
        return new self(
            resolution: self::IGNORED,
            leaveExternalId: $leaveExternalId,
            note: $note,
        );
    }

    public static function unmappable(string $note): self
    {
        return new self(resolution: self::UNMAPPABLE, note: $note);
    }

    public function shouldApply(): bool
    {
        return $this->resolution === self::APPLY;
    }

    public function isApproval(): bool
    {
        return $this->action === 'approved';
    }

    public function isCancellation(): bool
    {
        return $this->action === 'cancelled';
    }
}
