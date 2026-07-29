<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_settings_require_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('security.show'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/security')
                ->where('twoFactor.enabled', false)
                ->where('twoFactor.pending', false));
    }

    public function test_user_can_enable_confirm_and_disable_two_factor_authentication(): void
    {
        $user = User::factory()->create();
        $confirmedSession = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)
            ->withSession($confirmedSession)
            ->from(route('security.show'))
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('security.show'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->actingAs($user)
            ->withSession($confirmedSession)
            ->from(route('security.show'))
            ->post(route('two-factor.confirm'), ['code' => $this->currentCode($user)])
            ->assertRedirect(route('security.show'));

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertCount(8, $user->fresh()->recoveryCodes());

        $this->actingAs($user)
            ->withSession($confirmedSession)
            ->from(route('security.show'))
            ->delete(route('two-factor.disable'))
            ->assertRedirect(route('security.show'));

        $user->refresh();
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_enabled_user_must_complete_the_two_factor_challenge_to_sign_in(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        // Fortify rejects replaying the same TOTP used to confirm setup.
        // An adjacent-window code represents the next authenticator refresh.
        $code = $this->currentCode($user->fresh(), 1);

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'Password@123',
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge'));

        $this->post(route('two-factor.login.store'), ['code' => $code])
            ->assertRedirect('/home');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_user_can_sign_in_with_a_recovery_code_once(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $recoveryCode = $user->fresh()->recoveryCodes()[0];

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'Password@123',
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), ['recovery_code' => $recoveryCode])
            ->assertRedirect('/home');

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($recoveryCode, $user->fresh()->recoveryCodes());
    }

    private function enableTwoFactor(User $user): void
    {
        $confirmedSession = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)
            ->withSession($confirmedSession)
            ->post(route('two-factor.enable'));

        $user->refresh();

        $this->actingAs($user)
            ->withSession($confirmedSession)
            ->post(route('two-factor.confirm'), ['code' => $this->currentCode($user)]);

        $this->post(route('logout'));
    }

    private function currentCode(User $user, int $windowOffset = 0): string
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $google = app(Google2FA::class);

        return $google->oathTotp($secret, $google->getTimestamp() + $windowOffset);
    }
}
