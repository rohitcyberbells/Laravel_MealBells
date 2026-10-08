<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'employee_code',
        'external_id',
        'name',
        'email',
        'attendance_source',
        'is_meal_eligible',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
        'is_meal_eligible' => true,
        'attendance_source' => 'manual',
    ];

    protected $casts = [
        'is_meal_eligible' => 'boolean',
        'anonymised_at' => 'datetime',
    ];

    /**
     * Whether this person's details were removed at their request.
     *
     * The row is kept deliberately - deleting it would cascade every skip they
     * ever had, and those skips are what the daily counts were computed from.
     */
    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    public function anonymisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anonymised_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function skips()
    {
        return $this->hasMany(Skip::class);
    }
}
