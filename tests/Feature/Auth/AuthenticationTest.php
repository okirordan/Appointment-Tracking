<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_with_username_and_password()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'Password@123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_department_leadership_lands_on_department_work_after_login(): void
    {
        $user = User::factory()->role(Role::Commissioner)->create();

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'Password@123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dept.dashboard', absolute: false));
    }

    public function test_users_can_authenticate_with_staff_id()
    {
        $user = User::factory()->create(['employee_number' => 'MoES/2026/0421']);

        $response = $this->post('/login', [
            'username' => 'MoES/2026/0421',
            'password' => 'Password@123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_users_can_authenticate_with_organisational_email_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'jane.kaggwa@education.go.ug']);

        // Surrounding whitespace is ignored and email case does not matter.
        $response = $this->post('/login', [
            'username' => '  Jane.Kaggwa@Education.GO.UG  ',
            'password' => 'Password@123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_unknown_identifier_shows_a_clear_error(): void
    {
        $response = $this->post('/login', [
            'username' => 'does-not-exist@education.go.ug',
            'password' => 'Password@123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors([
            'username' => 'No account was found for that Staff ID, username or email address.',
        ]);
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_inactive_users_are_denied_even_with_valid_credentials()
    {
        $user = User::factory()->inactive()->create();

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'Password@123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('username');
    }

    public function test_locked_users_are_denied_even_with_valid_credentials()
    {
        $user = User::factory()->locked()->create();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'Password@123',
        ]);

        $this->assertGuest();
    }

    public function test_failed_logins_are_counted_and_reset_on_success()
    {
        $user = User::factory()->create();

        $this->post('/login', ['username' => $user->username, 'password' => 'wrong']);
        $this->assertSame(1, $user->fresh()->failed_login_count);

        $this->post('/login', ['username' => $user->username, 'password' => 'Password@123']);
        $this->assertSame(0, $user->fresh()->failed_login_count);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login', absolute: false));
    }
}
