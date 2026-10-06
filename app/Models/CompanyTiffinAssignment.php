<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyTiffinAssignment extends Model
{
    protected $fillable = [
        'company_id',
        'tiffin_service_id',
        'is_active',
        'assigned_at',
        'unassigned_at',
    ];

    protected static function boot()
    {
        parent::boot();

        /*
         * assigned_at is declared useCurrent() in the migration, which lets the
         * DATABASE clock set it. Defaulting it here instead keeps the value on
         * application time, so it honours Carbon's clock (including a frozen one
         * under test) rather than drifting away from it.
         */
        static::creating(function ($assignment) {
            if (empty($assignment->assigned_at)) {
                $assignment->assigned_at = now();
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function tiffinService()
    {
        return $this->belongsTo(TiffinService::class);
    }

    public function scopeActiveOn($query, $date)
    {
        $targetDate = is_string($date) ? $date : $date->toDateString();

        return $query->where('is_active', true)
            ->whereDate('assigned_at', '<=', $targetDate)
            ->where(function ($q) use ($targetDate) {
                $q->whereNull('unassigned_at')
                    ->orWhereDate('unassigned_at', '>', $targetDate);
            });
    }
}
