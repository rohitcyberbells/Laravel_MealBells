<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrmsWebhookEvent extends Model
{
    use Prunable;

    /** Accepted, not yet processed. */
    public const STATUS_RECEIVED = 'received';

    /** At least one day reached the desired state. */
    public const STATUS_APPLIED = 'applied';

    /**
     * Permanently unactionable: unknown employee, unmappable payload, or every
     * attempted day refused by a guard. Never retried.
     */
    public const STATUS_BLOCKED = 'blocked';

    /** Deliberately not actioned, e.g. WFH while wfh_auto_skip is off. */
    public const STATUS_IGNORED = 'ignored';

    /** A newer applied event for the same leave already superseded this one. */
    public const STATUS_STALE = 'stale';

    /** Transient error (DB down, deadlock). The only status the queue retries. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'external_event_id',
        'event_type',
        'leave_external_id',
        'occurred_at',
        'payload',
        'status',
        'result',
        'error',
        'processed_at',
        'reconcile_attempts',
        'last_reconciled_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'reconcile_attempts' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Events whose raw payload has outlived its usefulness.
     *
     * Rows that have already been redacted are excluded, so a daily prune does
     * not keep rewriting the same history.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('hrms.retention.payload_days', 30);

        return static::query()
            ->whereNotNull('payload')
            ->where('created_at', '<=', now()->subDays($days));
    }

    /**
     * Redacts instead of deleting, which is why prune() is overridden rather
     * than left to the trait.
     *
     * The payload carries PII - employee identifiers and leave reasons, which can
     * be medical - but status, result and timings are the audit trail for what
     * the integration did to someone's meals, so those are kept.
     */
    public function prune(): bool
    {
        $this->pruning();

        return $this->forceFill(['payload' => null])->save();
    }
}
