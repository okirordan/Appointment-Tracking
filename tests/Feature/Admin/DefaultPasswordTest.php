<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Support\DefaultPassword;
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

    public function test_new_accounts_receive_the_year_based_default_password_and_must_change_it(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $office = OrganizationalUnit::create([
            'name' => 'Staff Office',
            'code' => 'STAFF-OFFICE',
            'type' => 'office',
            'active' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'full_name' => 'Grace Nakato',
            'role' => Role::Officer->value,
            'username' => 'gnakato2',
            'organizational_unit_id' => $office->id,
        ])->assertSessionHasNoErrors();

        $user = User::where('username', 'gnakato2')->firstOrFail();
        $this->assertTrue(Hash::check('Changeme@'.now()->year, $user->password));
        $this->assertTrue($user->force_password_change);
        // The password is hashed at rest, never stored in clear.
        $this->assertStringStartsWith('$', $user->getRawOriginal('password'));
    }

    public function test_admin_reset_returns_the_account_to_the_current_default_password(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $user = User::factory()->locked()->create(['failed_login_count' => 7]);

        $this->actingAs($admin)->post(route('admin.users.reset-password', $user))
            ->assertSessionHas('temp_credential');

        $user->refresh();
        $this->assertTrue(Hash::check(DefaultPassword::value(), $user->password));
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
}
