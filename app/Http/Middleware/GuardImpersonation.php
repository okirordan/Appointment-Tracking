<?php

namespace App\Http\Middleware;

use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;

class GuardImpersonation
{
    public function handle(Request $request, Closure $next)
    {
        $service = app(ImpersonationService::class);
        if ($request->session()->has(ImpersonationService::KEY)) {
            $support = $service->current($request);
            if (! $support || $request->user()?->id !== $support->target_user_id) {
                if ($support) {
                    $service->close($support, 'Session identity invalid');
                }
                auth()->forgetUser();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }
            $target = User::find($support->target_user_id);
            if ($request->routeIs('impersonation.stop')) {
                return redirect()->route($service->finish($request) ? 'admin.users.index' : 'login');
            }
            if ($support->ended_at || $support->expires_at->isPast() || ! $service->validActor($support)
                || ! $target || ! $service->eligible($target) || $target->auth_session_version !== (int) $support->target_version) {
                return redirect()->route($service->finish($request, 'Expired or access revoked') ? 'admin.users.index' : 'login');
            }
            if ($request->routeIs('logout')) {
                $service->close($support, 'Signed out');
                $request->session()->forget(ImpersonationService::KEY);
            } else {
                abort_if($request->is('admin', 'admin/*', 'user/*', 'fortify/*', 'confirm-password', 'security', 'password/*', 'reset-password*', 'forgot-password', 'two-factor*', 'work-mode')
                    || (! $request->isMethodSafe() && $request->is('notification-settings*')), 403, 'Return to Super Admin before changing account or administration settings.');
            }
        }
        // The reserved supplementary role cannot be granted through browser forms.
        if (! $request->isMethodSafe() && $request->is('admin/*')) {
            $role = $request->route('role');
            $selectedRole = $request->integer('role_id') ? Role::find($request->integer('role_id')) : null;
            $position = $request->integer('position_id') ? Position::with('role')->find($request->integer('position_id')) : null;
            abort_if($selectedRole?->name === 'super_admin' || $position?->role?->name === 'super_admin', 403, 'Super Admin is a reserved server-managed role.');
            if (! $request->user()?->isSuperAdmin()) {
                $target = $request->route('user');
                $ids = array_filter([$target instanceof User ? $target->id : $target, $request->input('user_id'), $request->input('secretary_user_id')], 'is_numeric');
                $protectedTarget = User::withTrashed()->whereKey($ids)->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))->exists();
                abort_if($protectedTarget || ($role instanceof Role && $role->name === 'super_admin'), 403);
            }
        }

        return $next($request);
    }
}
