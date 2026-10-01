<?php

namespace App\Support;

use App\Enums\SkipOutcome;
use App\Models\Skip;

class SkipResult
{
    public function __construct(
        public readonly Skip $skip,
        public readonly SkipOutcome $outcome
    ) {}
}
