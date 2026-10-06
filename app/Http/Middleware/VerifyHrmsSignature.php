<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Hrms\HrmsConnectionResolver;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an inbound HRMS webhook before anything reads the body.
 *
 * Two modes, chosen per company because vendor capability varies:
 *   'signature' - HMAC-SHA256 over "{timestamp}.{raw body}". Preferred: the
 *                 secret never travels, and the timestamp bounds replay.
 *   'token'     - static bearer token, for vendors that offer nothing better.
 */
class VerifyHrmsSignature
{
    public function __construct(protected HrmsConnectionResolver $connections) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Size is checked before anything else, and deliberately before the
        // signature. A leave event is a couple of kilobytes, so without this an
        // unauthenticated caller could make us HMAC whatever PHP's post_max_size
        // allows, once per request.
        $maxBytes = (int) config('hrms.max_body_bytes', 262144);

        if (strlen($request->getContent()) > $maxBytes) {
            Log::warning("HRMS webhook rejected: body exceeds {$maxBytes} bytes.");

            return response()->json(['message' => 'Payload too large.'], 413);
        }

        $company = $request->route('company');

        if (! $company instanceof Company) {
            return $this->deny('Route is missing a resolved company.');
        }

        $webhook = $this->connections->webhookFor($company);

        if (! $webhook) {
            return $this->deny("No webhook secret configured for company {$company->id}.");
        }

        return match ($webhook['auth']) {
            'token' => $this->verifyToken($request, $next, $webhook),
            'signature' => $this->verifySignature($request, $next, $webhook),
            default => $this->deny("Unsupported webhook auth mode '{$webhook['auth']}'."),
        };
    }

    /**
     * @param  array<string, mixed>  $webhook
     */
    protected function verifyToken(Request $request, Closure $next, array $webhook): Response
    {
        $presented = (string) ($request->bearerToken() ?? '');

        if ($presented === '' || ! hash_equals((string) $webhook['secret'], $presented)) {
            return $this->deny('Invalid bearer token.');
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $webhook
     */
    protected function verifySignature(Request $request, Closure $next, array $webhook): Response
    {
        $presented = (string) ($request->header($webhook['signature_header']) ?? '');
        $timestamp = (string) ($request->header($webhook['timestamp_header']) ?? '');

        if ($presented === '' || $timestamp === '') {
            return $this->deny('Missing signature or timestamp header.');
        }

        $sentAt = $this->parseTimestamp($timestamp);

        if (! $sentAt) {
            return $this->deny('Unparseable timestamp header.');
        }

        // Replay window. Without it a captured request stays valid forever.
        if (abs($sentAt->diffInSeconds(now(), false)) > (int) $webhook['tolerance_seconds']) {
            return $this->deny('Timestamp outside tolerance window.');
        }

        // Signed over the RAW body. Re-encoding the decoded JSON would change key
        // order and whitespace, and the digest would never match.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), (string) $webhook['secret']);

        if (! hash_equals($expected, $this->normalizeDigest($presented))) {
            return $this->deny('Signature mismatch.');
        }

        return $next($request);
    }

    /**
     * Accepts a bare digest or a prefixed one such as "sha256=...", since
     * vendors differ on which they send.
     */
    protected function normalizeDigest(string $presented): string
    {
        if (! str_contains($presented, '=')) {
            return trim($presented);
        }

        return trim(substr($presented, strpos($presented, '=') + 1));
    }

    protected function parseTimestamp(string $timestamp): ?Carbon
    {
        try {
            return ctype_digit($timestamp)
                ? Carbon::createFromTimestamp((int) $timestamp)
                : Carbon::parse($timestamp);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The reason is logged but never returned: the response must not tell a
     * prober which part of their credential was wrong.
     */
    protected function deny(string $reason): Response
    {
        Log::warning("HRMS webhook rejected: {$reason}");

        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
