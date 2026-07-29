<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * A single identifier field accepts a Staff ID, username, or official
     * email address (AUTH-010). Surrounding whitespace is ignored and
     * emails/staff IDs match case-insensitively. Inactive or locked
     * accounts are rejected with the same generic message as bad
     * credentials so account state is never revealed (AUTH-003, AUTH-004).
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $user = $this->resolveUser();

        if ($user === null) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'username' => __('No account was found for that Staff ID, username or email address.'),
            ]);
        }

        $blocked = $user->locked || ! $user->active || ! $user->isRoleActive();

        $password = $this->string('password')->toString();

        if ($blocked || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($this->throttleKey());

            $user->increment('failed_login_count');
            event(new Failed('web', $user, [
                'username' => $this->string('username')->toString(),
                'password' => $password,
            ]));

            // PWD-007: configurable lockout after repeated failures.
            $lockoutAfter = (int) config('ats.lockout_after_failures');
            if (! $user->locked && $user->failed_login_count >= $lockoutAfter) {
                $user->forceFill(['locked' => true])->save();
                app(AuditLogger::class)->log('security',
                    "Account {$user->username} locked after {$lockoutAfter} failed sign-in attempts",
                    outcome: 'failure', actorName: $user->username);
            }

            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower(trim($this->string('username')->toString())).'|'.$this->ip());
    }

    /**
     * Resolve the entered identifier to an account. Usernames win over
     * staff IDs, which win over emails, so a collision can never silently
     * sign someone into another person's account. Soft-deleted users are
     * excluded by the model's global scope.
     */
    private function resolveUser(): ?User
    {
        $identifier = Str::lower(trim($this->string('username')->toString()));

        if ($identifier === '') {
            return null;
        }

        return User::query()->whereRaw('LOWER(username) = ?', [$identifier])->first()
            ?? User::query()->whereRaw('LOWER(employee_number) = ?', [$identifier])->first()
            ?? User::query()->whereRaw('LOWER(email) = ?', [$identifier])->first();
    }
}
