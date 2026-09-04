<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password','role','company_id','tiffin_service_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory , Notifiable;
    
    public function company()
{
    return $this->belongsTo(Company::class);
}

public function tiffinService()
{
    return $this->belongsTo(TiffinService::class);
}

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
