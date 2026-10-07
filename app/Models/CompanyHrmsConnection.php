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
        'pull_base_url',
        'pull_email',
        'pull_password',
        'pull_token',
        'pull_token_expires_at',
        'pull_adapter',
        'last_pull_at',
        'last_pull_status',
        'last_pull_summary',
        'last_pull_error',
        'last_login_at',
    ];

    protected $casts = [
        // Encrypted at rest: a leaked database dump must not hand over the
        // ability to forge signed webhooks for every tenant, nor to sign in to a
        // customer's HR system.
        'webhook_secret' => 'encrypted',
        'pull_base_url' => 'encrypted',
        'pull_email' => 'encrypted',
        'pull_password' => 'encrypted',
        'pull_token' => 'encrypted',
        'secret_rotated_at' => 'datetime',
        'pull_token_expires_at' => 'datetime',
        'last_pull_at' => 'datetime',
        'last_login_at' => 'datetime',
        'last_pull_summary' => 'array',
    ];

    /**
     * Never serialised to the frontend. The plaintext is shown exactly once, at
     * the moment it is generated, and is passed explicitly rather than through
     * the model.
     */
    protected $hidden = [
        'webhook_secret',
        'pull_base_url',
        'pull_email',
        'pull_password',
        'pull_token',
    ];

    /**
     * A usable token we are still inside the validity of.
     *
     * The vendor's token cannot be refreshed, so a run either has one or logs in
     * again; there is no half state worth modelling.
     */
    public function hasLiveToken(): bool
    {
        return ! empty($this->pull_token)
            && $this->pull_token_expires_at !== null
            && $this->pull_token_expires_at->isFuture();
    }

    public function hasPullCredentials(): bool
    {
        return ! empty($this->pull_base_url)
            && ! empty($this->pull_email)
            && ! empty($this->pull_password);
    }

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
