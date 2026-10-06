<?php

namespace App\Services\Hrms\Adapters;

/**
 * The default: passes the body through untouched.
 *
 * Field naming is already handled by 'payload_map', so a vendor whose JSON is
 * flat or addressable by dot paths needs nothing more than this. Having it as a
 * real adapter rather than a null check keeps the seam honest - a vendor-specific
 * adapter is a new class and a config line, not a change to the mapper.
 */
class GenericHrmsAdapter implements HrmsVendorAdapter
{
    public function name(): string
    {
        return 'generic';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function toGeneric(array $payload): array
    {
        return $payload;
    }
}
