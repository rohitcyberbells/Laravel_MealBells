<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMustChangePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            // Allow access ONLY to password change routes and logout
            if (! $request->routeIs('password.change', 'password.update', 'logout') &&
                ! $request->is('change-password', 'logout')) {
                return redirect()->route('password.change');
            }
        }

        return $next($request);
    }
}
