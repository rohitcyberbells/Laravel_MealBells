<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'company_id', 'tiffin_service_id', 'login_code', 'must_change_password', 'last_login_at'])]
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
        ];
    }
}
