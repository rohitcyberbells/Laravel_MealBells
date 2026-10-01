<?php

namespace App\Exceptions;

use App\Enums\MealRuleReason;
use Exception;

class MealRuleViolation extends Exception
{
    public function __construct(
        string $message,
        public readonly MealRuleReason $reasonCode,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getReasonCode(): MealRuleReason
    {
        return $this->reasonCode;
    }

    public function getReasonCodeString(): string
    {
        return $this->reasonCode->value;
    }
}
