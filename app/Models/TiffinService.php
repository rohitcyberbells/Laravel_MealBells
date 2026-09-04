<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TiffinService extends Model
{
    protected $fillable = [
        'name',
        'address',
        'contact_phone',
    ];

    public function assignments(){
         return $this->hasMany(CompanyTiffinAssignment::class);
    }
}
