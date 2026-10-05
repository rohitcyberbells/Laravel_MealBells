<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringSkip extends Model
{
    protected $fillable = [
        'company_id',
        'employee_id',
        'weekday',
        'starts_on',
        'ends_on',
        'active',
        'created_by',
    ];

    protected $casts = [
        'weekday' => 'integer',
        'active' => 'boolean',
    ];
   

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
