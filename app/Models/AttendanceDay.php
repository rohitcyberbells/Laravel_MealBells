<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the HR system said about one employee on one day.
 *
 * Holds a decision, not a measurement: `clocked_in_by_cutoff` is the answer to
 * "had they arrived by the time we had to order", worked out once against the
 * company's cutoff in its own timezone. The arrival time itself is never
 * stored - see docs/attendance-design.md for why.
 */
class AttendanceDay extends Model
{
    protected $fillable = [
        'company_id',
        'employee_id',
        'date',
        'clocked_in_by_cutoff',
        'is_wfh',
        'source',
    ];

    protected $casts = [
        'date' => 'date',
        'clocked_in_by_cutoff' => 'boolean',
        'is_wfh' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Known to have been absent by the cutoff.
     *
     * Null is excluded deliberately: it means the HR system had no answer for
     * this person, which is not the same as their having been away, and
     * treating it as an absence is exactly the mistake that would cost somebody
     * their lunch.
     */
    public function scopeAbsent($query)
    {
        return $query->where('clocked_in_by_cutoff', false);
    }

    public function scopeUnknown($query)
    {
        return $query->whereNull('clocked_in_by_cutoff');
    }
}
