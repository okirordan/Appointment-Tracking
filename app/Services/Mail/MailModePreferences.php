<?php

namespace App\Services\Mail;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Presentation choices never grant access to mail or administration. */
class MailModePreferences
{
    public function available(?User $user): bool
    {
        return $user !== null && (in_array($user->role, [Role::Secretary, Role::Ps], true) || $user->can('viewAny', MailRecord::class));
    }

    public function startLogin(Request $request, User $user): void
    {
        if (! $request->hasSession() || ! $this->available($user)
            || $request->routeIs('admin.users.impersonate', 'impersonation.stop')
            || $request->session()->has(ImpersonationService::KEY)) {
            return;
        }
        $request->session()->put($this->key($user), $this->newSession($user));
        $request->session()->forget('mail_mode.'.$user->id);
    }

    private function newSession(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['mail_login_count' => $locked->mail_login_count + 1])->save();

            return [
                'login' => $locked->mail_login_count,
                'chosen' => false,
                'ask' => $locked->mail_default_mode === 'ask',
                'mode' => $locked->mail_onboarded_at && $locked->mail_default_mode === 'basic' ? 'basic' : 'full',
            ];
        });
    }

    private function session(Request $request): array
    {
        $user = $request->user();
        $key = $this->key($user);
        if (! $request->session()->has($key)) {
            // Existing authenticated sessions also get the introduction. Deduplicate
            // concurrent first requests from tabs before their session has been saved.
            $cacheKey = 'mail-intro:'.hash('sha256', $request->session()->getId().':'.$user->id);
            $state = Cache::lock($cacheKey.':lock', 10)->block(5, fn () => Cache::remember($cacheKey, now()->addDays(30), fn () => $this->newSession($user)));
            $request->session()->put($key, $state);
        }

        return $request->session()->get($key);
    }

    public function mode(Request $request): string
    {
        if (! $this->available($request->user())) {
            return 'full';
        }
        if ($request->session()->has(ImpersonationService::KEY)) {
            $key = 'mail_mode.'.$request->user()->id;
            $mode = $request->query('mode');
            if (in_array($mode, ['basic', 'full'], true)) {
                $request->session()->put($key, $mode);
            }

            return $request->session()->get($key, 'full');
        }
        $state = $this->session($request);
        $mode = $request->query('mode');
        if (in_array($mode, ['basic', 'full'], true)) {
            $state['mode'] = $mode;
            $state['chosen'] = true;
            $request->session()->put($this->key($request->user()), $state);
        }
        if ($state['mode'] === 'basic') {
            $this->markTried($request->user());
        }

        return $state['mode'];
    }

    public function state(Request $request): ?array
    {
        $user = $request->user();
        if (! $this->available($user) || $user->force_password_change || $request->session()->has(ImpersonationService::KEY)) {
            return null;
        }
        $mode = $this->mode($request);
        $session = $this->session($request);
        $user = $user->fresh();
        $prompt = ! $user->mail_onboarded_at ? 'welcome'
            : (($session['ask'] ?? false) && ! $session['chosen'] ? 'choose' : null);

        return [
            'user_id' => $user->id,
            'active_mode' => $mode,
            'default_mode' => in_array($user->mail_default_mode, ['basic', 'full', 'ask'], true) ? $user->mail_default_mode : 'full',
            'prompt' => $prompt,
            'basic_tried' => $user->mail_basic_tried_at !== null,
            'tips_enabled' => $user->mail_tips_enabled,
            'tips_dismissed' => $user->mail_tips_dismissed_at !== null,
            'login_sequence' => $session['login'],
            'tip_eligible' => $prompt === null && $mode === 'full' && $session['login'] >= 2
                && $user->mail_tips_enabled && ! $user->mail_basic_tried_at
                && ($user->mail_tip_last_login === null || $session['login'] - $user->mail_tip_last_login >= 5),
        ];
    }

    public function choose(Request $request, string $mode, bool $saveDefault): void
    {
        $user = $request->user();
        $state = $this->session($request);
        User::whereKey($user->id)->whereNull('mail_onboarded_at')->update(['mail_onboarded_at' => now()]);
        if ($saveDefault) {
            User::whereKey($user->id)->update(['mail_default_mode' => $mode]);
        }
        $request->session()->put($this->key($user), [...$state, 'chosen' => true, 'mode' => $mode]);
        if ($mode === 'basic') {
            $this->markTried($user);
        }
        // Preference requests must not reapply a stale mode from a referring URL.
        $request->query->remove('mode');
    }

    public function save(Request $request, array $data): void
    {
        $attributes = [];
        if (array_key_exists('default_mode', $data)) {
            $attributes['mail_default_mode'] = $data['default_mode'];
        }
        if (array_key_exists('tips_enabled', $data)) {
            $attributes['mail_tips_enabled'] = $data['tips_enabled'];
            $attributes['mail_tips_dismissed_at'] = $data['tips_enabled'] ? null : now();
        }
        if ($attributes !== []) {
            User::whereKey($request->user()->id)->update($attributes);
        }
    }

    public function claimTip(Request $request): bool
    {
        $state = $this->state($request);
        if (! ($state['tip_eligible'] ?? false)) {
            return false;
        }

        // The conditional write is atomic across tabs and devices.
        return User::whereKey($request->user()->id)
            ->whereNotNull('mail_onboarded_at')->whereNull('mail_basic_tried_at')->where('mail_tips_enabled', true)
            ->where(fn ($q) => $q->whereNull('mail_tip_last_login')->orWhere('mail_tip_last_login', '<=', $state['login_sequence'] - 5))
            ->update(['mail_tip_last_login' => $state['login_sequence']]) === 1;
    }

    private function markTried(User $user): void
    {
        if ($user->mail_basic_tried_at !== null) {
            return;
        }

        $when = now();
        User::whereKey($user->id)->whereNull('mail_basic_tried_at')->update(['mail_basic_tried_at' => $when]);
        $user->forceFill(['mail_basic_tried_at' => $when]);
        $user->syncOriginalAttribute('mail_basic_tried_at');
    }

    private function key(User $user): string
    {
        return 'mail_experience.'.$user->id;
    }
}
