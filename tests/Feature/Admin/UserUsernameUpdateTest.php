<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Role as PermissionRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserUsernameUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_change_a_login_username_with_history_and_audit(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $user = User::factory()->role(Role::Officer)->create(['username' => 'old.username']);
        $officerRole = PermissionRole::where('name', Role::Officer->value)->firstOrFail();

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'username' => '  New.Username  ',
            'full_name' => $user->full_name,
            'role_id' => $officerRole->id,
            'reason' => 'Aligned the login with the staff naming convention.',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'username' => 'new.username',
        ]);
        $this->assertDatabaseHas('user_profile_changes', [
            'user_id' => $user->id,
            'field_name' => 'username',
            'old_value' => 'old.username',
            'new_value' => 'new.username',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'user',
            'target_type' => 'User',
            'target_id' => $user->id,
        ]);

        $this->post(route('logout'));
        $this->post('/login', [
            'username' => 'new.username',
            'password' => 'Password@123',
        ])->assertRedirect(route('home'));
    }

    public function test_username_change_must_remain_unique_after_normalization(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        User::factory()->create(['username' => 'existing.user']);
        $user = User::factory()->role(Role::Officer)->create(['username' => 'current.user']);
        $officerRole = PermissionRole::where('name', Role::Officer->value)->firstOrFail();

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'username' => ' Existing.User ',
            'full_name' => $user->full_name,
            'role_id' => $officerRole->id,
        ])->assertSessionHasErrors('username');

        $this->assertSame('current.user', $user->fresh()->username);
    }
}
