<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    protected $fillable = [
        'company_id',
        'employee_code',
        'name',
        'email',
        'attendance_source',
        'is_meal_eligible',
        'status',
    ];

    protected $casts = [
        'is_meal_eligible' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function skips()
    {
        return $this->hasMany(Skip::class);
    }
}
