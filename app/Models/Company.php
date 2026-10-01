<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'address',
        'contact_phone',
    ];

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
