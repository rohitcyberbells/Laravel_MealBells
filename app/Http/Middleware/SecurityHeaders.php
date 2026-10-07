<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers the browser needs in order to defend the page.
 *
 * The app shipped with none of these. They are cheap and they only constrain,
 * so the risk is breaking something rather than missing something - which is
 * why the Content-Security-Policy starts in report-only mode.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Stop the page being framed. MealBells is never embedded, and this
        // blocks clickjacking an admin into a destructive button.
        $response->headers->set('X-Frame-Options', 'DENY');

        // No MIME sniffing: an uploaded CSV must not be executed as something
        // else because a browser guessed.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Send the origin to other sites, never the path - URLs here carry
        // company codes and employee ids.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Nothing in the app uses these, so they are refused outright rather
        // than left to a prompt.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        // Only over HTTPS: sending it over plain http is meaningless, and
        // sending it from a local dev server would pin the browser to https for
        // localhost across every project on the machine.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('security.hsts_max_age', 31536000).'; includeSubDomains'
            );
        }

        $this->applyContentSecurityPolicy($request, $response);

        return $response;
    }

    /**
     * Report-only to begin with.
     *
     * Vite serves modules from its own origin in development and Inertia ships
     * the page as inline JSON, so an enforcing policy written blind would break
     * the application rather than protect it. Report-only means the browser
     * tells us what it would have blocked while everything keeps working;
     * enforcement is a one-line change once the reports are quiet.
     */
    protected function applyContentSecurityPolicy(Request $request, Response $response): void
    {
        if (! config('security.csp.enabled', true)) {
            return;
        }

        // The dev server's origin, so a report-only policy is not noisy with
        // things that are correct locally.
        $vite = app()->environment('local') ? ' http://localhost:* http://127.0.0.1:* http://[::1]:*' : '';

        $policy = implode('; ', [
            "default-src 'self'",
            // 'unsafe-inline' is required while Inertia writes the page payload
            // into a script tag; a nonce is the way out of it later.
            "script-src 'self' 'unsafe-inline'".$vite,
            "style-src 'self' 'unsafe-inline'".$vite,
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'".($vite ? $vite.' ws://localhost:* ws://127.0.0.1:*' : ''),
            "form-action 'self'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
        ]);

        $header = config('security.csp.enforce', false)
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        $response->headers->set($header, $policy);
    }
}
