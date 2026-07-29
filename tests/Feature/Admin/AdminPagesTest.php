<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_department_and_duplicates_are_rejected()
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();

        $this->actingAs($admin)->post('/admin/departments', [
            'name' => 'Basic Education',
            'code' => 'BSE',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departments', ['code' => 'BSE']);
        $this->assertDatabaseHas('audit_logs', ['category' => 'department']);

        $this->actingAs($admin)->post('/admin/departments', [
            'name' => 'Basic Education II',
            'code' => 'BSE',
        ])->assertSessionHasErrors('code');
    }

    public function test_department_removal_is_deactivation()
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $department = Department::factory()->create();

        $this->actingAs($admin)->post("/admin/departments/{$department->id}/toggle-active");

        $this->assertFalse($department->fresh()->active);
        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    public function test_audit_log_is_admin_only_and_filterable()
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $ps = User::factory()->role(Role::Ps)->create();

        $this->actingAs($ps)->get('/admin/audit-log')->assertForbidden();

        // Two sign-ins above already produced audit entries via actingAs? No —
        // actingAs skips the login event, so create entries explicitly.
        app(\App\Services\AuditLogger::class)->log('user', 'Created user account test', $admin);
        app(\App\Services\AuditLogger::class)->log('task', 'Created task X', $admin);

        $this->actingAs($admin)->get('/admin/audit-log?category=task')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/audit-log')
                ->count('logs.data', 1)
                ->where('logs.data.0.category', 'task'));
    }

    public function test_branding_settings_are_saved_and_audited()
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();

        $this->actingAs($admin)->post('/admin/settings', [
            'ministry_full_name' => 'Ministry of Education and Sports',
            'ministry_short_name' => 'MoES',
            'system_title' => 'ATS Production',
        ])->assertSessionHasNoErrors();

        $this->assertSame('ATS Production', Setting::value('system_title'));
        $this->assertDatabaseHas('audit_logs', ['category' => 'settings']);
    }

    public function test_demo_purge_is_disabled_by_default()
    {
        config(['ats.allow_demo_purge' => false]);
        $admin = User::factory()->role(Role::Sysadmin)->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/admin/settings/purge-demo-data')
            ->assertForbidden();
    }

    public function test_admin_password_reset_issues_temp_password_and_forces_change()
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $user = User::factory()->role(Role::Officer)->locked()->create(['failed_login_count' => 7]);

        $response = $this->actingAs($admin)->post("/admin/users/{$user->id}/reset-password");

        $user->refresh();
        $this->assertTrue($user->force_password_change);
        $this->assertFalse($user->locked);
        $this->assertSame(0, $user->failed_login_count);
        $this->assertNotNull($user->password_reset_at);
        $this->assertSame($admin->id, $user->password_reset_by);
        $this->assertDatabaseHas('audit_logs', ['category' => 'security', 'target_id' => $user->id]);

        // The temporary password is delivered as a one-time credential for the
        // copyable dialog — not embedded in the auto-dismissing toast.
        $response->assertSessionHas('temp_credential');
        $credential = session('temp_credential');
        $this->assertSame($user->username, $credential['username']);
        $this->assertNotEmpty($credential['password']);
        $this->assertStringNotContainsString($credential['password'], (string) session('success'));
    }

    public function test_new_user_receives_temporary_credential_for_copy_dialog()
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'full_name' => 'New Officer',
            'role' => 'officer',
            'username' => 'nofficer',
        ]);

        $response->assertSessionHas('temp_credential');
        $this->assertSame('nofficer', session('temp_credential')['username']);
        $this->assertSame('created', session('temp_credential')['context']);
    }

    public function test_forced_password_change_gate_blocks_navigation()
    {
        $user = User::factory()->role(Role::Officer)->create(['force_password_change' => true]);

        $this->actingAs($user)->get('/home')->assertRedirect('/password/change');
        $this->actingAs($user)->get('/tasks')->assertRedirect('/password/change');
        $this->actingAs($user)->get('/password/change')->assertOk();
    }

    public function test_user_changes_password_and_gate_lifts()
    {
        $user = User::factory()->role(Role::Officer)->create(['force_password_change' => true]);

        $this->actingAs($user)->post('/password/change', [
            'current_password' => 'Password@123',
            'password' => 'NewSecret@2026',
            'password_confirmation' => 'NewSecret@2026',
        ])->assertRedirect(route('home', absolute: false));

        $this->assertFalse($user->fresh()->force_password_change);
        $this->actingAs($user)->get('/home')->assertOk();
    }

    public function test_weak_passwords_are_rejected()
    {
        $user = User::factory()->role(Role::Officer)->create();

        $this->actingAs($user)->post('/password/change', [
            'current_password' => 'Password@123',
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertSessionHasErrors('password');
    }

    public function test_account_locks_after_repeated_failures()
    {
        config(['ats.lockout_after_failures' => 3]);
        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $this->post('/login', ['username' => $user->username, 'password' => 'wrong']);
        }

        $this->assertTrue($user->fresh()->locked);

        // Correct password no longer works once locked.
        $this->post('/login', ['username' => $user->username, 'password' => 'Password@123']);
        $this->assertGuest();
    }

    public function test_security_headers_are_present()
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }
}
