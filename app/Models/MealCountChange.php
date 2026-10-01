<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealCountChange extends Model
{
    protected $fillable = [
        'meal_count_id',
        'change_quantity',
        'reason',
        'requested_by',
    ];

    public function mealCount()
    {
        return $this->belongsTo(MealCount::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
