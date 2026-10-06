<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CompanyHrmsConnection extends Model
{
    protected $fillable = [
        'company_id',
        'webhook_secret',
        'auth',
        'secret_rotated_at',
        'rotated_by',
    ];

    protected $casts = [
        // Encrypted at rest: a leaked database dump must not hand over the
        // ability to forge signed webhooks for every tenant.
        'webhook_secret' => 'encrypted',
        'secret_rotated_at' => 'datetime',
    ];

    /**
     * Never serialised to the frontend. The plaintext is shown exactly once, at
     * the moment it is generated, and is passed explicitly rather than through
     * the model.
     */
    protected $hidden = ['webhook_secret'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rotated_by');
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
