<?php

namespace App\Services;

use App\Enums\Role;
use App\Http\Middleware\EnsureAccountAccessIsCurrent;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class ImpersonationService
{
    public const KEY = 'ats_impersonation_id';

    public function current(Request $request): ?ImpersonationSession
    {
        $id = $request->hasSession() ? $request->session()->get(self::KEY) : null;

        return is_string($id) ? ImpersonationSession::find($id) : null;
    }

    public function eligible(User $target): bool
    {
        return $target->mayAuthenticate() && ! $target->force_password_change
            && $target->role !== Role::Sysadmin
            && ! $target->roles()->where('name', 'super_admin')->exists() && ! $target->can('admin.access');
    }

    public function banner(Request $request): ?array
    {
        $support = $this->current($request);

        return $support && ! $support->ended_at && $request->user()?->id === $support->target_user_id
            ? ['user_name' => $request->user()->full_name, 'expires_at' => $support->expires_at->toIso8601String()]
            : null;
    }

    public function start(Request $request, User $target): void
    {
        $actor = $request->user();
        abort_unless($actor?->isSuperAdmin() && ! $actor->force_password_change && ! $request->session()->has(self::KEY), 403);
        abort_unless($target->id !== $actor->id && $this->eligible($target), 403, 'Select an active non-administrator whose account setup is complete.');
        $support = ImpersonationSession::create([
            'id' => (string) Str::uuid(), 'actor_user_id' => $actor->id, 'target_user_id' => $target->id,
            'actor_version' => $actor->auth_session_version, 'target_version' => $target->auth_session_version,
            'started_at' => now(), 'expires_at' => now()->addHour(), 'ip_address' => $request->ip(),
        ]);
        app(AuditLogger::class)->log('impersonation', 'Started user impersonation', $actor, 'User', $target->id, [
            'support_id' => $support->id, 'impersonated_user' => $target->full_name, 'started_at' => $support->started_at->toIso8601String(),
        ]);
        $this->switchUser($request, $target);
        $request->session()->put(self::KEY, $support->id);
    }

    public function validActor(ImpersonationSession $support): ?User
    {
        $actor = User::find($support->actor_user_id);

        return $actor?->isSuperAdmin() && ! $actor->force_password_change
            && $actor->auth_session_version === (int) $support->actor_version ? $actor : null;
    }

    public function close(ImpersonationSession $support, string $reason): void
    {
        $changed = ImpersonationSession::whereKey($support->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => $reason]);
        if ($changed) {
            app(AuditLogger::class)->log('impersonation', 'Ended user impersonation', User::find($support->actor_user_id), 'User', $support->target_user_id, [
                'support_id' => $support->id, 'started_at' => $support->started_at->toIso8601String(), 'ended_at' => now()->toIso8601String(), 'reason' => $reason,
            ]);
        }
    }

    public function finish(Request $request, string $reason = 'Returned to Super Admin'): bool
    {
        $support = $this->current($request);
        abort_unless($support && $request->user()?->id === $support->target_user_id, 403);
        $actor = $this->validActor($support);
        $this->close($support, $reason);
        if ($actor && ! $support->ended_at) {
            $this->switchUser($request, $actor);

            return true;
        }
        Auth::guard('web')->forgetUser();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return false;
    }

    private function switchUser(Request $request, User $user): void
    {
        $guard = Auth::guard('web');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget($guard->getRecallerName()));
        $request->session()->put($guard->getName(), $user->id);
        $request->session()->put(EnsureAccountAccessIsCurrent::SESSION_VERSION_KEY, $user->auth_session_version);
        $guard->setUser($user);
    }
}
