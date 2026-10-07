<?php

namespace App\Services\Hrms\CyberPulse;

/**
 * Outcome of one fetch against CyberPulse.
 *
 * Success and failure are kept explicit rather than signalled by an empty array,
 * because the pull's cancellation step treats them completely differently: a
 * failed fetch looks exactly like "every leave was cancelled", and acting on
 * that would wipe a day's skips.
 */
class CyberPulseResult
{
    /**
     * @param  array<int, array<string, mixed>>  $leaves  whitelisted rows, vendor shape
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $leaves = [],
        public readonly ?string $error = null,
        public readonly ?int $status = null,
        public readonly bool $loggedIn = false,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $leaves
     */
    public static function success(array $leaves, bool $loggedIn = false): self
    {
        return new self(ok: true, leaves: $leaves, loggedIn: $loggedIn);
    }

    public static function failure(string $error, ?int $status = null): self
    {
        return new self(ok: false, error: $error, status: $status);
    }
}
