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
    public function assignments(){
        return $this->hasMany(CompanyTiffinAssignment::class);
    }

    public function activeAssignment(){
        return $this->hasOne(CompanyTiffinAssignment::class)->where('is_active', true);
    } 
}
