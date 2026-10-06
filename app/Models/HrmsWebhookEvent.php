<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrmsWebhookEvent extends Model
{
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
}
