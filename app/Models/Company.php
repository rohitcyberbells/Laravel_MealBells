<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = [
        'code',
        'name',
        'address',
        'contact_phone',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($company) {
            if (empty($company->code)) {
                $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $company->name ?? 'CMP'), 0, 4));
                if (strlen($base) < 3) {
                    $base = 'CMP';
                }
                do {
                    $code = $base.Str::upper(Str::random(4));
                } while (static::where('code', $code)->exists());

                $company->code = $code;
            }
        });
    }

    public function assignments()
    {
        return $this->hasMany(CompanyTiffinAssignment::class);
    }

    public function activeAssignment()
    {
        return $this->hasOne(CompanyTiffinAssignment::class)->where('is_active', true);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function setting()
    {
        return $this->hasOne(CompanySetting::class);
    }

    public function skips()
    {
        return $this->hasMany(Skip::class);
    }

    public function mealAdjustments()
    {
        return $this->hasMany(MealAdjustment::class);
    }

    public function mealCounts()
    {
        return $this->hasMany(MealCount::class);
    }
}
