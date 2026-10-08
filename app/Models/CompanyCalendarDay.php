<?php

namespace App\Models;

use App\Services\MealCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyCalendarDay extends Model
{
    protected $fillable = [
        'company_id',
        'date',
        'type',
        'note',
        'created_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Any write invalidates the per-request memo of this company's calendar.
     *
     * On the model rather than in the actions, because a seeder, a test or a
     * future caller writing the row directly would otherwise leave a stale
     * answer behind - which is exactly what happened the first time this was
     * only wired into the actions.
     */
    protected static function booted(): void
    {
        $invalidate = fn (self $day) => MealCalendar::forget((int) $day->company_id);

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
