<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'company_id', 'tiffin_service_id', 'login_code', 'must_change_password', 'last_login_at', 'is_active', 'deactivated_at', 'deactivated_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Whether a password-reset mail could actually reach this account.
     *
     * An employee with no address of their own carries a stand-in one, which is
     * never written to - so a reset link for them would be sent nowhere and
     * they would be left waiting. Their HR team resets them instead.
     */
    public function canReceiveMail(): bool
    {
        $email = trim((string) $this->email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $suffix = (string) config('mealbells.placeholder_email_suffix', '.local');

        return ! str_ends_with(strtolower($email), strtolower($suffix));
    }

    /**
     * Whether this account may be signed in right now.
     *
     * Read in two places that must agree: the sign-in check, and the middleware
     * that re-checks it on every request. It used to exist only in the login
     * controller, which meant deactivating someone - or archiving their company
     * - did nothing to a session they already had. They kept working until it
     * expired.
     *
     * The three switches are owned by different people: an employee is stood
     * down on their employee record by their HR team, any account can be
     * deactivated by a super admin, and archiving a company or a tiffin service
     * takes everyone attached to it with it.
     */
    public function canSignIn(): bool
    {
        // Explicitly false, not merely falsy. The column is NOT NULL DEFAULT
        // true, so a null here never comes from the database - it only means
        // the attribute was not loaded, as happens with a model built by
        // create() where the default was applied server-side. Treating that as
        // deactivated locked out every such account.
        if ($this->is_active === false || $this->is_active === 0) {
            return false;
        }

        // An archived company's people are refused. The company is
        // soft-deleted, so the relation resolves to null while company_id still
        // points at it - which is exactly the condition to catch.
        if ($this->company_id !== null && $this->company === null) {
            return false;
        }

        // The same for an archived tiffin service. Archiving deactivates its
        // logins, so is_active already catches them; this is the second lock,
        // for an account reactivated by hand or created after the archive.
        if ($this->tiffin_service_id !== null && $this->tiffinService === null) {
            return false;
        }

        if ($this->role !== 'employee') {
            return true;
        }

        return ! $this->employee || $this->employee->status === 'active';
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function tiffinService()
    {
        return $this->belongsTo(TiffinService::class);
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }
}
