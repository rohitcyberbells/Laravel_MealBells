<?php

namespace App\Events;

use App\Models\MealCount;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DailyCountConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(public MealCount $mealCount) {}
}
