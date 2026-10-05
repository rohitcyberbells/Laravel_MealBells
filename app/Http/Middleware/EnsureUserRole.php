<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect('/login');
        }

        // Active employee status check
        if ($user->role === 'employee' && $user->employee && $user->employee->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'Your employee account is inactive.');
        }

        if (! in_array($user->role, $roles)) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
