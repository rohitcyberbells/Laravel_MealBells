<?php

namespace App\Services\Hrms\Adapters;

/**
 * Normalizes a vendor's webhook body into the generic envelope the mapper reads.
 *
 * Most vendors need no adapter: differing field NAMES are handled declaratively
 * by 'payload_map' in config/hrms.php. An adapter exists for the shapes config
 * cannot express - a vendor that wraps each event in an array, nests its own
 * envelope, or sends a different structure per event type.
 */
interface HrmsVendorAdapter
{
    /**
     * A short identifier for logs and the connect screen.
     */
    public function name(): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function toGeneric(array $payload): array;
}
