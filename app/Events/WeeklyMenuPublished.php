<?php

namespace App\Events;

use App\Models\WeeklyMenu;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WeeklyMenuPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(public WeeklyMenu $weeklyMenu) {}
}
