<?php

namespace Tests\Feature\Admin;

use App\Enums\Role as SystemRole;
use App\Models\Department;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfficerPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_administrator_promotes_an_existing_officer_and_preserves_work_and_history(): void
    {
        $admin = User::factory()->role(SystemRole::Sysadmin)->create();
        $patrick = User::factory()->role(SystemRole::Officer)->create([
            'full_name' => 'Patrick Emmanuel Muinda',
            'employee_number' => '13524',
            'title' => 'Human Resource Officer',
        ]);
        $task = Task::factory()->create([
            'assigned_by_user_id' => $admin->id,
            'assigned_to_user_id' => $patrick->id,
            'current_assignee_user_id' => $patrick->id,
            'responsible_user_id' => $patrick->id,
        ]);
        $department = Department::factory()->create(['name' => 'Libraries, E-Learning and Information Technology']);
        $unit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'Department of Libraries, E-Learning and Information Technology',
            'code' => 'LEIT',
            'active' => true,
        ]);
        $commissionerRole = Role::where('name', SystemRole::Commissioner->value)->firstOrFail();
        $position = Position::create([
            'organizational_unit_id' => $unit->id,
            'role_id' => $commissionerRole->id,
            'title' => 'Commissioner',
            'hierarchy_level' => 20,
            'workflow_capabilities' => ['assign', 'review', 'approve'],
            'active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.users.update', $patrick), [
            'username' => $patrick->username,
            'full_name' => $patrick->full_name,
            'title' => $patrick->title,
            'email' => $patrick->email,
            'employee_number' => $patrick->employee_number,
            'role_id' => $commissionerRole->id,
            'department_id' => $department->id,
            'division_id' => null,
            'organizational_unit_id' => $unit->id,
            'position_id' => $position->id,
            'supervisor_user_id' => null,
            'effective_date' => '2026-07-01',
            'reason' => 'Promotion approved by the appointing authority.',
        ])->assertRedirect();

        $patrick->refresh()->unsetRelation('roles');
        $this->assertSame(SystemRole::Commissioner, $patrick->role);
        $this->assertSame('commissioner', $patrick->roleName());
        $this->assertSame('Commissioner', $patrick->title);
        $this->assertTrue($patrick->can('assignments.approve'));
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'assigned_to_user_id' => $patrick->id,
            'current_assignee_user_id' => $patrick->id,
        ]);
        $this->assertDatabaseHas('user_position_changes', [
            'user_id' => $patrick->id,
            'previous_title' => 'Human Resource Officer',
            'new_title' => 'Commissioner',
            'changed_by_user_id' => $admin->id,
        ]);
        $this->assertSame('2026-07-01', $patrick->positionChanges()->firstOrFail()->effective_date->toDateString());

        $this->actingAs($patrick->fresh())
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.role', 'commissioner')
                ->where('auth.user.role_label', 'Commissioner')
                ->where('auth.user.title', 'Commissioner'));
        $this->get(route('dept.dashboard'))->assertOk();
        $this->get(route('officer.dashboard'))->assertForbidden();
    }

    public function test_officer_grade_positions_keep_the_officer_dashboard_and_exact_titles(): void
    {
        $admin = User::factory()->role(SystemRole::Sysadmin)->create();
        $department = Department::factory()->create();
        $unit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'Human Resource Management',
            'code' => 'HRM',
            'active' => true,
        ]);
        $officerRole = Role::where('name', SystemRole::Officer->value)->firstOrFail();

        foreach (['Principal Human Resource Officer', 'Senior Human Resource Officer', 'Human Resource Officer'] as $index => $title) {
            $user = User::factory()->role(SystemRole::Officer)->create(['title' => 'Officer']);
            $position = Position::create([
                'organizational_unit_id' => $unit->id,
                'role_id' => $officerRole->id,
                'title' => $title,
                'hierarchy_level' => 40 + ($index * 10),
                'active' => true,
            ]);

            $this->actingAs($admin)->post(route('admin.hierarchy.appointments.store'), [
                'user_id' => $user->id,
                'position_id' => $position->id,
                'starts_at' => '2026-07-01',
                'reason' => 'Approved officer placement.',
            ])->assertRedirect();

            $user->refresh()->unsetRelation('roles');
            $this->assertSame(SystemRole::Officer, $user->role);
            $this->assertSame($title, $user->title);
            $this->actingAs($user)->get(route('officer.dashboard'))->assertOk();
        }
    }
}
