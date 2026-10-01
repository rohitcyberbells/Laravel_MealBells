<?php

namespace App\Enums;

enum SkipOutcome: string
{
    case CREATED = 'created';
    case REACTIVATED = 'reactivated';
    case ALREADY_SKIPPED = 'already_skipped';
    case BLOCKED_CANCELLED = 'blocked_cancelled';
}
