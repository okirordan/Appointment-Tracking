<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-008: accounts flagged for mandatory password change cannot use the
 * application until a new password is set. Server-enforced, not client state.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && $user->force_password_change
            && ! $request->routeIs('password.change', 'password.change.store', 'logout')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
