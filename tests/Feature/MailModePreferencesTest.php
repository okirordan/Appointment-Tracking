<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailModePreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): void
    {
        $this->post('/logout');
        $this->travel(2)->minutes();
        $this->post('/login', ['username' => $user->username, 'password' => 'Password@123'])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function preferences(): array
    {
        return $this->get('/home')->assertOk()->inertiaProps('modePreferences');
    }

    public function test_first_login_uses_full_and_introduces_basic_once_without_refresh_counting_as_login(): void
    {
        $user = User::factory()->role(Role::Secretary)->create();
        $this->login($user);
        $this->assertSame('welcome', $this->preferences()['prompt']);
        $this->assertSame('full', $this->preferences()['active_mode']);
        $this->assertSame(1, $user->fresh()->mail_login_count);
        $this->postJson('/mode-preferences/tip')->assertJson(['show' => false]);
        $this->postJson('/mode-preferences/choose', ['mode' => 'later'])->assertOk()->assertJsonPath('preferences.prompt', null);
        $this->assertNull($this->preferences()['prompt']);
        $this->login($user);
        $this->assertNull($this->preferences()['prompt']);
        $this->assertSame(2, $user->fresh()->mail_login_count);
    }

    public function test_explicit_default_survives_login_but_manual_switch_does_not_replace_it(): void
    {
        $user = User::factory()->role(Role::Secretary)->create();
        $this->login($user);
        $this->postJson('/mode-preferences/choose', ['mode' => 'basic', 'save_default' => true])
            ->assertOk()->assertJsonPath('preferences.default_mode', 'basic')->assertJsonPath('preferences.basic_tried', true);
        $this->get('/incoming-mail?mode=full')->assertOk();
        $this->assertSame('basic', $user->fresh()->mail_default_mode);
        $this->assertSame('full', $this->preferences()['active_mode']);
        $this->login($user);
        $this->get('/home')->assertOk()->assertInertia(fn ($page) => $page->component('mail/index')->where('mailMode', 'basic'));
    }

    public function test_temporary_selection_and_decide_later_preserve_existing_default(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        $user->forceFill(['mail_default_mode' => 'basic'])->save();
        $this->login($user);
        $this->postJson('/mode-preferences/choose', ['mode' => 'later', 'save_default' => true])
            ->assertOk()->assertJsonPath('preferences.active_mode', 'full')->assertJsonPath('preferences.default_mode', 'basic');
        $this->postJson('/mode-preferences/choose', ['mode' => 'full', 'save_default' => false])->assertOk();
        $this->assertSame('basic', $user->fresh()->mail_default_mode);
    }

    public function test_ask_each_time_prompts_once_each_login_and_settings_do_not_switch_current_mode(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        $this->login($user);
        $this->postJson('/mode-preferences/choose', ['mode' => 'full'])->assertOk();
        $this->putJson('/mode-preferences', ['default_mode' => 'ask'])->assertOk()->assertJsonPath('preferences.active_mode', 'full');
        $this->login($user);
        $this->assertSame('choose', $this->preferences()['prompt']);
        $this->postJson('/mode-preferences/tip')->assertJson(['show' => false]);
        $this->postJson('/mode-preferences/choose', ['mode' => 'later'])->assertOk();
        $this->assertNull($this->preferences()['prompt']);
        $this->login($user);
        $this->assertSame('choose', $this->preferences()['prompt']);
    }

    public function test_enabling_ask_during_an_existing_login_does_not_open_a_dialog_until_the_next_login(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        $user->forceFill(['mail_onboarded_at' => now(), 'mail_default_mode' => 'full'])->save();
        $this->login($user);
        $this->putJson('/mode-preferences', ['default_mode' => 'ask'])->assertOk()->assertJsonPath('preferences.prompt', null);
        $this->login($user);
        $this->assertSame('choose', $this->preferences()['prompt']);
    }

    public function test_tips_follow_logins_two_seven_twelve_and_claims_are_not_repeated(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        for ($login = 1; $login <= 12; $login++) {
            $this->login($user);
            if ($login === 1) {
                $this->postJson('/mode-preferences/choose', ['mode' => 'later'])->assertOk();
            }
            $this->postJson('/mode-preferences/tip')->assertOk()->assertJson(['show' => in_array($login, [2, 7, 12])]);
            $this->get('/incoming-mail')->assertOk();
            $this->postJson('/mode-preferences/tip')->assertJson(['show' => false]);
            $this->assertSame($login, $user->fresh()->mail_login_count);
        }
    }

    public function test_disabled_tips_and_trying_basic_are_persistent_and_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        $other = User::factory()->role(Role::Ps)->create();
        $this->login($user);
        $this->postJson('/mode-preferences/choose', ['mode' => 'later'])->assertOk();
        $this->putJson('/mode-preferences', ['tips_enabled' => false, 'user_id' => $other->id])->assertOk();
        $this->assertTrue($other->fresh()->mail_tips_enabled);
        $this->login($user);
        $this->postJson('/mode-preferences/tip')->assertJson(['show' => false]);
        $this->putJson('/mode-preferences', ['tips_enabled' => true])->assertOk()->assertJsonPath('preferences.tips_dismissed', false);
        $this->postJson('/mode-preferences/tip')->assertJson(['show' => true]);
        $this->get('/incoming-mail?mode=basic')->assertOk();
        $this->get('/incoming-mail?mode=full')->assertOk();
        $this->assertTrue($this->preferences()['basic_tried']);
        $this->login($user);
        $this->postJson('/mode-preferences/tip')->assertJson(['show' => false]);
        $this->assertNull($other->fresh()->mail_basic_tried_at);
    }

    public function test_invalid_preferences_are_rejected_and_guests_cannot_update_them(): void
    {
        $this->putJson('/mode-preferences', ['default_mode' => 'basic'])->assertUnauthorized();
        $this->actingAs(User::factory()->role(Role::Ps)->create())
            ->putJson('/mode-preferences', ['default_mode' => 'invalid'])->assertUnprocessable();
    }

    public function test_two_factor_login_counts_only_after_verification_and_opens_the_saved_basic_mode(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        $user->forceFill([
            'mail_onboarded_at' => now(), 'mail_default_mode' => 'basic',
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['test-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->post('/login', ['username' => $user->username, 'password' => 'Password@123'])->assertRedirect(route('two-factor.login'));
        $this->assertSame(0, $user->fresh()->mail_login_count);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'test-recovery-code'])->assertRedirect('/home');
        $this->assertSame(1, $user->fresh()->mail_login_count);
        $this->get('/home')->assertOk()->assertInertia(fn ($page) => $page->component('mail/index')->where('mailMode', 'basic'));
    }
}
