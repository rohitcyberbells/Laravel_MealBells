<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A URL this server may be told to sign in to.
 *
 * Two separate concerns:
 *
 * 1. Confidentiality. The pull posts a password to this host, so in production
 *    it must be https. Locally http is allowed, because a developer's stub HRMS
 *    has no certificate and forcing one would only push people to disable the
 *    check entirely.
 *
 * 2. SSRF. The host is supplied by a company admin and the server then makes
 *    requests to it. Without a check, "https://10.0.0.5/api" turns the feature
 *    into a way to probe the private network from inside it, with our own
 *    credentials and our own network position. Link-local (169.254.x) is the
 *    worst of them: on a cloud host that is the instance metadata endpoint.
 *
 * This blocks literal addresses and the obvious names. It is not airtight - a
 * public hostname resolving to a private address still passes, and DNS can be
 * re-pointed after validation - so it is a guard against the common mistake,
 * not a substitute for restricting egress.
 */
class SafeHrmsBaseUrl implements ValidationRule
{
    /**
     * Run even when the value is empty, so the rule is meaningful on its own
     * rather than only alongside 'required'.
     */
    public bool $implicit = true;

    /**
     * Private and internal ranges, matched against an IP literal only.
     *
     * These are checked after confirming the host IS an address: applied to a
     * name, '/^10\./' would also reject a perfectly ordinary host called
     * 10.example.com.
     *
     * 172.16-31 is included although the brief did not name it: it is the third
     * RFC 1918 block, and leaving it out would block two thirds of the private
     * space and quietly admit the rest.
     */
    protected const BLOCKED_IP_PATTERNS = [
        '/^127\./',
        '/^10\./',
        '/^192\.168\./',
        '/^169\.254\./',
        '/^172\.(1[6-9]|2[0-9]|3[01])\./',
        '/^0\.0\.0\.0$/',
        '/^::1$/',
        // Unique-local and link-local IPv6.
        '/^f[cd][0-9a-f]{2}:/i',
        '/^fe80:/i',
    ];

    /**
     * Names that resolve to this machine however they are spelled.
     */
    protected const BLOCKED_HOSTS = ['localhost', 'localhost.localdomain', 'ip6-localhost'];

    /**
     * Hosts allowed only because a developer needs a stub to point at.
     */
    protected const LOCAL_ONLY_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = is_string($value) ? trim($value) : '';
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            $fail('Enter a full URL including https://.');

            return;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $relaxed = app()->environment(['local', 'testing']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The URL must start with https://.');

            return;
        }

        if ($scheme === 'http' && ! ($relaxed && in_array($host, self::LOCAL_ONLY_HOSTS, true))) {
            $fail('The URL must use https, because this sends a password to that host.');

            return;
        }

        if ($relaxed && in_array($host, self::LOCAL_ONLY_HOSTS, true)) {
            return;
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            $fail('That address is on a private or internal network, which this server will not connect to.');

            return;
        }

        // Strip the brackets an IPv6 host carries in a URL before testing it.
        $address = trim($host, '[]');

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            // A name. Where it resolves to is not checked - a public hostname
            // pointing at a private address still passes, and DNS can be
            // re-pointed after validation, so that belongs at egress rather
            // than here.
            return;
        }

        foreach (self::BLOCKED_IP_PATTERNS as $pattern) {
            if (preg_match($pattern, $address)) {
                $fail('That address is on a private or internal network, which this server will not connect to.');

                return;
            }
        }
    }
}
