<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkMode
{
    public function handle(Request $request, Closure $next, string $mode): Response
    {
        if ($request->user()?->role === Role::Sysadmin
            && $request->session()->get('work_mode', 'administration') !== $mode) {
            abort(403, $mode === 'officer'
                ? 'Switch to Officer Mode to access assignments and correspondence.'
                : 'Switch to System Administration Mode to access administration settings.');
        }

        return $next($request);
    }
}
