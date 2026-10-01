<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealAdjustment extends Model
{
    protected $fillable = [
        'company_id',
        'date',
        'quantity',
        'type',
        'reason',
        'created_by',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
