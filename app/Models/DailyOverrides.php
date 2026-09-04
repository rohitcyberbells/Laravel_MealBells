<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyOverrides extends Model
{
    protected $table = 'daily_overrides';
    
    protected $fillable = [
    'tiffin_service_id',
    'date',
    'meal_description',
    'reason',
];

public function tiffinService()
{
    return $this->belongsTo(TiffinService::class);
}

}
