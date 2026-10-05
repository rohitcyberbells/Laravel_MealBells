<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyMenu extends Model
{
    protected $fillable = [
        'tiffin_service_id',
        'week_start_date',
        'status',
    ];

    public function tiffinService()
    {
        return $this->belongsTo(TiffinService::class);
    }

    public function items()
    {
        return $this->hasMany(MenuItem::class);
    }
}
