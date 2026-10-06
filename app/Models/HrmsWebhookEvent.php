<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrmsWebhookEvent extends Model
{
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
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
