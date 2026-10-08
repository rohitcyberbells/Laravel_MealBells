<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks on every request what sign-in checked once.
 *
 * Without this, deactivating an account did nothing to a session that account
 * already had: somebody stood down this morning kept working until their
 * session expired, which with the default lifetime is two hours. Archiving a
 * company or a tiffin service had the same hole - the point of archiving is
 * that its people lose access, and they did not.
 *
 * The rule itself is User::canSignIn(), shared with the login check so the two
 * cannot drift.
 */
class EnsureAccountIsStillActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $user->canSignIn()) {
            return $next($request);
        }

        Auth::logout();

        // Invalidated and the token regenerated, so nothing of the old session
        // survives to be replayed - this runs because access was revoked, which
        // is exactly when a lingering session matters.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Said in the same words as a wrong password. The person may have been
        // deactivated, or their company archived, and neither is this
        // response's business to explain.
        return redirect()->route('login')->withErrors([
            'identifier' => 'These credentials do not match our records.',
        ]);
    }
}
