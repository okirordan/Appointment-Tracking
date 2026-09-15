<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_session_lock_does_not_depend_on_the_failover_cache(): void
    {
        config(['cache.default' => 'failover', 'cache.stores.redis.driver' => 'unavailable-test']);
        app('cache')->extend('unavailable-test', fn () => app('cache')->repository(new class extends ArrayStore
        {
            public function lock($name, $seconds = 0, $owner = null)
            {
                return new class($name, $seconds, $owner) extends Lock
                {
                    public function acquire()
                    {
                        throw new \RuntimeException('Redis is unavailable during lock acquisition');
                    }

                    public function release()
                    {
                        return true;
                    }

                    public function forceRelease()
                    {
                        return true;
                    }

                    protected function getCurrentOwner()
                    {
                        return $this->owner;
                    }
                };
            }
        }));
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.impersonate', $target))->assertRedirect(route('home'));
        $this->post(route('impersonation.stop'))->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
    }

    private function admin(): User
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_ordinary_admin_and_secretary_cannot_start_impersonation(): void
    {
        $target = User::factory()->create();
        foreach ([Role::Sysadmin, Role::Secretary, Role::Officer] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->post(route('admin.users.impersonate', $target))->assertForbidden();
        }
        $this->assertDatabaseCount('impersonation_sessions', 0);
    }

    public function test_super_admin_uses_target_identity_and_can_return_without_passwords(): void
    {
        $admin = $this->admin();
        $target = User::factory()->role(Role::Secretary)->create();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time(), 'work_mode' => 'administration'])
            ->post(route('admin.users.impersonate', $target))->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($target);
        $this->assertNull(session('auth.password_confirmed_at'));
        $support = ImpersonationSession::firstOrFail();
        $this->assertSame($admin->id, $support->actor_user_id);
        $this->get('/admin/users')->assertForbidden();
        $this->post('/notification-settings/push-subscriptions', [])->assertForbidden();
        $this->get('/user/two-factor-secret-key')->assertForbidden();
        $this->post(route('impersonation.stop'))->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(ImpersonationService::KEY));
        $this->assertNotNull($support->fresh()->ended_at);
    }

    public function test_return_remains_available_if_the_target_is_disabled(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.impersonate', $target));
        $target->update(['active' => false]);
        $this->post(route('impersonation.stop'))->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_actor_password_reset_invalidates_restoration(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.impersonate', $target));
        $admin->update(['password' => 'Changed-password-123!']);
        $this->post(route('impersonation.stop'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotNull(ImpersonationSession::first()->ended_at);
    }

    public function test_ordinary_admin_cannot_assign_reserved_role_or_change_super_admin_password(): void
    {
        $super = $this->admin();
        $ordinary = User::factory()->role(Role::Sysadmin)->create();
        $role = \App\Models\Role::where('name', 'super_admin')->firstOrFail();
        $this->actingAs($ordinary)->post('/admin/users', ['role_id' => $role->id])->assertForbidden();
        $this->put('/admin/roles/'.$role->id, [])->assertForbidden();
        $this->post('/admin/users/'.$super->id.'/reset-password', [])->assertForbidden();
    }

    public function test_primary_role_stays_system_administrator_and_supplement_survives_updates(): void
    {
        $admin = $this->admin();
        $admin->syncRoles(['sysadmin']);
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertSame('sysadmin', $admin->roleName());
    }

    public function test_reserved_accounts_stay_protected_when_disabled_and_in_hierarchy_forms(): void
    {
        $super = $this->admin();
        $ordinary = User::factory()->role(Role::Sysadmin)->create();
        $this->actingAs($ordinary)->post('/admin/hierarchy/secretary-attachments', ['secretary_user_id' => $super->id])->assertForbidden();
        $super->update(['locked' => true, 'active' => false]);
        $this->post('/admin/users/'.$super->id.'/reset-password')->assertForbidden();
        $super->delete();
        $this->post('/admin/users/'.$super->id.'/restore')->assertForbidden();
    }

    public function test_security_routes_and_nested_impersonation_are_blocked(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.impersonate', $target));
        $this->post(route('two-factor.enable'))->assertForbidden();
        $this->post(route('two-factor.regenerate-recovery-codes'))->assertForbidden();
        $this->post(route('admin.users.impersonate', User::factory()->create()))->assertForbidden();
        $this->assertDatabaseCount('impersonation_sessions', 1);
    }

    public function test_administrator_targets_and_unready_accounts_are_rejected(): void
    {
        $admin = $this->admin();
        foreach ([User::factory()->role(Role::Sysadmin)->create(), User::factory()->create(['force_password_change' => true]), User::factory()->inactive()->create()] as $target) {
            $this->actingAs($admin)->post(route('admin.users.impersonate', $target))->assertForbidden();
        }
    }

    public function test_expired_support_stops_before_the_requested_action_and_records_an_end(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.impersonate', $target));
        $this->travel(61)->minutes();
        $this->get(route('home'))->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull(ImpersonationSession::first()->ended_at);
        $this->assertDatabaseHas('audit_logs', ['category' => 'impersonation', 'action' => 'Ended user impersonation']);
    }

    public function test_impersonated_actions_record_both_identities_and_logout_ends_support(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.impersonate', $target));
        $this->get('/admin/users')->assertForbidden();
        $entry = AuditLog::where('category', 'failed_action')->latest('id')->firstOrFail();
        $this->assertSame($target->id, $entry->actor_user_id);
        $this->assertSame($admin->id, $entry->metadata_json['super_admin_id']);
        $this->assertSame($target->id, $entry->metadata_json['impersonated_user_id']);
        $this->post(route('logout'));
        $this->assertGuest();
        $this->assertNotNull(ImpersonationSession::first()->ended_at);
    }

    public function test_error_pages_keep_the_support_banner_available(): void
    {
        config(['app.debug' => false]);
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.impersonate', $target));
        $this->get('/admin/users')->assertForbidden()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('errors/error')->where('impersonation.user_name', $target->full_name));
        $this->post(route('impersonation.stop'))->assertRedirect(route('admin.users.index'));
    }
}
