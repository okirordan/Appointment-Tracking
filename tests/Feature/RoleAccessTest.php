<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_officers_cannot_reach_admin_pages()
    {
        $officer = User::factory()->role(Role::Officer)->create();

        $this->actingAs($officer)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($officer)->get('/admin/users')->assertForbidden();
        $this->actingAs($officer)->get('/reports')->assertForbidden();
        $this->actingAs($officer)->get('/officer-lookup')->assertForbidden();
    }

    public function test_sysadmin_can_reach_admin_pages()
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }

    public function test_clerk_cannot_reach_reports_or_performance()
    {
        $clerk = User::factory()->role(Role::Clerk)->create();

        $this->actingAs($clerk)->get('/reports')->assertForbidden();
        $this->actingAs($clerk)->get('/officer-performance')->assertForbidden();
        $this->actingAs($clerk)->get('/executive/dashboard')->assertForbidden();
    }

    public function test_commissioner_reaches_department_scope_pages()
    {
        $commissioner = User::factory()->role(Role::Commissioner)->create();

        $this->actingAs($commissioner)->get('/department/dashboard')->assertOk();
        $this->actingAs($commissioner)->get('/correspondence')->assertOk();
        $this->actingAs($commissioner)->get('/executive/dashboard')->assertForbidden();
    }
}
