<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountAccessIsCurrent
{
    public const SESSION_VERSION_KEY = 'auth.session_version';

    public const PENDING_VERSION_KEY = 'login.auth_session_version';

    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->user();

        if ($authenticatedUser !== null) {
            $currentUser = User::withTrashed()->with('roles')->find($authenticatedUser->getAuthIdentifier());

            if (! $this->isCurrentSessionValid($request, $currentUser)) {
                return $this->terminateInvalidSession($request);
            }

            Auth::guard('web')->setUser($currentUser);
            $request->session()->put(self::SESSION_VERSION_KEY, $currentUser->auth_session_version);
        } elseif ($request->session()->has('login.id')) {
            $pendingUser = User::withTrashed()->with('roles')->find($request->session()->get('login.id'));
            $pendingVersion = $request->session()->get(self::PENDING_VERSION_KEY);

            if (! $pendingUser?->mayAuthenticate()
                || ($pendingVersion !== null && (int) $pendingVersion !== $pendingUser->auth_session_version)) {
                $request->session()->forget(['login.id', 'login.remember', self::PENDING_VERSION_KEY]);

                return $this->terminateInvalidSession($request);
            }
        }

        $response = $next($request);

        if ($authenticatedUser === null && ($user = Auth::guard('web')->user()) instanceof User) {
            $request->session()->put(self::SESSION_VERSION_KEY, $user->auth_session_version);
            $request->session()->forget(self::PENDING_VERSION_KEY);
        }

        return $response;
    }

    private function isCurrentSessionValid(Request $request, ?User $user): bool
    {
        if (! $user?->mayAuthenticate()) {
            return false;
        }

        $sessionVersion = $request->session()->get(self::SESSION_VERSION_KEY);

        return $sessionVersion === null || (int) $sessionVersion === $user->auth_session_version;
    }

    private function terminateInvalidSession(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your session is no longer authorised. Please sign in again.');
    }
}
