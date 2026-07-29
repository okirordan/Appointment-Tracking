<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ATS already owns login, logout, password confirmation, and account
        // lifecycle routes. Register only the Fortify 2FA endpoints explicitly
        // in routes/auth.php so package route names cannot shadow them.
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        Fortify::twoFactorChallengeView(
            fn (Request $request) => Inertia::render('auth/two-factor-challenge')
        );

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id', $request->ip()));
        });

        Event::listen(TwoFactorAuthenticationEnabled::class, function (TwoFactorAuthenticationEnabled $event) {
            $this->audit('Two-factor authentication setup started', $event->user);
        });
        Event::listen(TwoFactorAuthenticationConfirmed::class, function (TwoFactorAuthenticationConfirmed $event) {
            $this->audit('Two-factor authentication enabled', $event->user);
        });
        Event::listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $event) {
            $this->audit('Two-factor authentication disabled', $event->user);
        });
        Event::listen(RecoveryCodesGenerated::class, function (RecoveryCodesGenerated $event) {
            $this->audit('Two-factor recovery codes regenerated', $event->user);
        });
    }

    private function audit(string $message, mixed $user): void
    {
        app(AuditLogger::class)->log('security', $message, $user instanceof User ? $user : null);
    }
}
