<?php

namespace App\Events;

use App\Models\MealCount;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DailyCountConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public MealCount $mealCount) {}
}
