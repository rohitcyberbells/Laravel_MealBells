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
