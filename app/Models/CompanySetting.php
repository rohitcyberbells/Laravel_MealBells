<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySetting extends Model
{
    protected $fillable = [
        'company_id',
        'cutoff_time',
        'timezone',
        'wfh_auto_skip',
        'meal_days',
        'primary_admin_id',
        'backup_admin_id',
        'updated_by',
        'settings_changed_at',
    ];

    protected $casts = [
        'settings_changed_at' => 'datetime',
        'wfh_auto_skip' => 'boolean',
        'meal_days' => 'array',
    ];

    public function getMealDaysAttribute($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) && count($decoded) > 0 ? $decoded : [1, 2, 3, 4, 5];
        }

        return is_array($value) && count($value) > 0 ? $value : [1, 2, 3, 4, 5];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function primaryAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_admin_id');
    }

    public function backupAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'backup_admin_id');
    }

    /**
     * Who last changed these settings. Null for a row nothing has touched since
     * the column was added, or whose user has since been deleted.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
