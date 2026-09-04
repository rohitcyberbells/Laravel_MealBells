<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyTiffinAssignment extends Model
{
    
    protected $fillable = [
        'company_id',
        'tiffin_service_id',
        'is_active',
        'assigned_at',
        'unassigned_at',
    ];
    
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
    
    public function tiffinService()
    {
        return $this->belongsTo(TiffinService::class);
    }
}
