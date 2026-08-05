<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Services\SecretaryAuthorityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCapability
{
    public function __construct(private SecretaryAuthorityService $secretaryAuthority) {}

    public function handle(Request $request, Closure $next, string $permission, string ...$legacyRoles): Response
    {
        $user = $request->user();
        abort_if(
            $user?->role === Role::Sysadmin && str_starts_with($permission, 'mail.'),
            403,
            'PS-office correspondence is not available to System Administrators.',
        );
        abort_unless(
            $user !== null
                && ($user->can($permission)
                    || $this->secretaryAuthority->allows($user, $permission)
                    || in_array($user->role->value, $legacyRoles, true)),
            403,
        );

        return $next($request);
    }
}
