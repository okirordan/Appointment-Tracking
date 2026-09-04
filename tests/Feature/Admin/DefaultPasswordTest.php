<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Support\DefaultPassword;
use App\Support\TemporaryPassword;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DefaultPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_password_is_derived_from_the_current_system_year(): void
    {
        $this->assertSame('Changeme@'.now()->year, DefaultPassword::value());

        $this->travelTo(now()->addYear(), function () {
            $this->assertSame('Changeme@'.now()->year, DefaultPassword::value());
        });
    }

    public function test_demo_seeded_accounts_must_change_their_password_before_using_the_application(): void
    {
        $this->seed([DepartmentSeeder::class, UserSeeder::class]);

        $users = User::query()->get();

        $this->assertNotEmpty($users);
        foreach ($users as $user) {
            $this->assertTrue($user->force_password_change, $user->username);
            $this->assertNull($user->password_changed_at, $user->username);
            $this->assertFalse(Hash::check(DefaultPassword::value(), $user->password), $user->username);
        }
    }

    public function test_temporary_passwords_are_unique_and_satisfy_the_password_policy(): void
    {
        $first = TemporaryPassword::generate();
        $second = TemporaryPassword::generate();

        $this->assertNotSame($first, $second);
        $this->assertGreaterThanOrEqual(20, strlen($first));
        $this->assertMatchesRegularExpression('/[a-z]/', $first);
        $this->assertMatchesRegularExpression('/[A-Z]/', $first);
        $this->assertMatchesRegularExpression('/[0-9]/', $first);
        $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $first);
    }

    public function test_demo_user_seeder_creates_no_accounts_in_production(): void
    {
        $this->seed(DepartmentSeeder::class);
        $this->app->detectEnvironment(fn () => 'production');

        app(UserSeeder::class)->run();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_new_accounts_receive_a_unique_temporary_password_and_must_change_it(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $office = OrganizationalUnit::create([
            'name' => 'Staff Office',
            'code' => 'STAFF-OFFICE',
            'type' => 'office',
            'active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'full_name' => 'Grace Nakato',
            'role' => Role::Officer->value,
            'username' => 'gnakato2',
            'organizational_unit_id' => $office->id,
        ])->assertSessionHasNoErrors();

        $user = User::where('username', 'gnakato2')->firstOrFail();
        $credential = $response->getSession()->get('temp_credential.password');
        $this->assertIsString($credential);
        $this->assertTrue(Hash::check($credential, $user->password));
        $this->assertNotSame(DefaultPassword::value(), $credential);
        $this->assertTrue($user->force_password_change);
        // The password is hashed at rest, never stored in clear.
        $this->assertStringStartsWith('$', $user->getRawOriginal('password'));
    }

    public function test_admin_reset_issues_a_unique_temporary_password(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $user = User::factory()->locked()->create(['failed_login_count' => 7]);

        $response = $this->actingAs($admin)->post(route('admin.users.reset-password', $user))
            ->assertSessionHas('temp_credential');

        $user->refresh();
        $credential = $response->getSession()->get('temp_credential.password');
        $this->assertIsString($credential);
        $this->assertTrue(Hash::check($credential, $user->password));
        $this->assertNotSame(DefaultPassword::value(), $credential);
        $this->assertTrue($user->force_password_change);
        $this->assertFalse($user->locked);
        $this->assertSame(0, $user->failed_login_count);
    }

    public function test_password_resets_are_audited_without_recording_the_password(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.users.reset-password', $user));

        $entry = AuditLog::where('category', 'security')
            ->where('target_type', 'User')
            ->where('target_id', $user->id)
            ->firstOrFail();

        $this->assertStringContainsString('Reset password', $entry->action);
        $this->assertStringNotContainsString('Changeme@', json_encode($entry->getAttributes()));
    }

    public function test_first_login_with_the_default_password_forces_a_change(): void
    {
        $user = User::factory()->create([
            'password' => DefaultPassword::value(),
            'force_password_change' => true,
        ]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => DefaultPassword::value(),
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);

        // Every page is gated until the default password is replaced.
        $this->get(route('home'))->assertRedirect(route('password.change', absolute: false));
        $this->get('/tasks')->assertRedirect(route('password.change', absolute: false));
    }

    public function test_forced_password_change_blocks_account_security_routes_until_rotation(): void
    {
        $user = User::factory()->create([
            'password' => DefaultPassword::value(),
            'force_password_change' => true,
        ]);

        $this->actingAs($user)
            ->get(route('password.confirm'))
            ->assertRedirect(route('password.change', absolute: false));

        $this->actingAs($user)
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('password.change', absolute: false));
    }
}
