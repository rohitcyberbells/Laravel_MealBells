<?php

namespace App\Events;

use App\Models\DailyOverrides;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DailyMealOverridden
{
    use Dispatchable, SerializesModels;

    public function __construct(public DailyOverrides $override) {}
}
