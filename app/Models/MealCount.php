<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealCount extends Model
{
    protected $fillable = [
        'company_id',
        'tiffin_service_id',
        'date',
        'base_eligible_count',
        'skip_count',
        'extra_count',
        'final_expected_count',
        'breakdown',
        'status',
        'confirmed_by',
        'locked_at',
    ];

    protected $casts = [
        'breakdown' => 'array',
        'locked_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function tiffinService(): BelongsTo
    {
        return $this->belongsTo(TiffinService::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function changes()
    {
        return $this->hasMany(MealCountChange::class);
    }

    public function getAdjustedTotalAttribute(): int
    {
        return $this->final_expected_count + $this->changes()->sum('change_quantity');
    }
}
