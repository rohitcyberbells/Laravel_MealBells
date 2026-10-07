<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pull run.
 *
 * A pull has no per-event delivery to inspect, so these rows are the only
 * evidence it is working - and the only way to see that it stopped, which looks
 * exactly like a quiet day of no leave.
 */
class HrmsPullRun extends Model
{
    use Prunable;

    public const STATUS_OK = 'ok';

    /** Finished, but a safety guard stopped it acting. Stale, not wrong. */
    public const STATUS_SUSPICIOUS = 'suspicious';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'adapter',
        'status',
        'dry_run',
        'fetched',
        'applied',
        'cancelled',
        'ignored',
        'unknown_employee',
        'duplicate',
        'cancel_candidates',
        'warnings',
        'error',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'warnings' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Deleted outright rather than redacted, unlike a webhook event: there is no
     * PII here to preserve the audit of, only counts.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('hrms.retention.pull_run_days', 30);

        return static::where('created_at', '<=', now()->subDays($days));
    }
}
