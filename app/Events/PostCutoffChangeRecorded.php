<?php

namespace App\Events;

use App\Models\MealCountChange;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostCutoffChangeRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public MealCountChange $change
    ) {}
}
