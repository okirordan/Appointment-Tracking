<?php

namespace Tests\Feature;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\AssignmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ps_department_summary_exposes_stable_drilldown_ids(): void
    {
        $department = Department::factory()->create();
        $ps = User::factory()->role(Role::Ps)->create();

        $this->actingAs($ps)->get(route('exec.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canDrillDownDepartmentPerformance', true)
                ->where('department_performance.0.id', $department->id));
    }

    public function test_executive_dashboard_counts_ps_level_tasks()
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $assignee = User::factory()->role(Role::Commissioner)->create();

        Task::factory()->level(AssignmentLevel::Ps)->count(2)->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
        Task::factory()->level(AssignmentLevel::Ps)->overdue()->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
        // Department-level task must not count.
        Task::factory()->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $this->actingAs($ps)->get('/executive/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboards/executive')
                ->where('stats.total', 3)
                ->where('stats.overdue', 1)
                ->count('stale', 1));
    }

    public function test_department_dashboard_scopes_to_own_department()
    {
        $dept = Department::factory()->create();
        $otherDept = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $dept->id]);
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $dept->id]);

        Task::factory()->count(2)->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $officer->id,
            'department_id' => $dept->id,
        ]);
        Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $officer->id,
            'department_id' => $otherDept->id,
        ]);

        $this->actingAs($commissioner)->get('/department/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboards/department')
                ->where('stats.total', 2));
    }

    public function test_department_dashboard_counts_assignments_issued_at_any_level()
    {
        // The reported bug: a Commissioner was assigned an activity by the
        // PS (a PS-level assignment) and the department dashboard showed
        // zeros because it only counted department-level records.
        $dept = Department::factory()->create();
        $ps = User::factory()->role(Role::Ps)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $dept->id]);

        Task::factory()->level(AssignmentLevel::Ps)->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $commissioner->id,
            'department_id' => $dept->id,
        ]);

        $this->actingAs($commissioner)->get('/department/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 1)
                ->where('stats.active', 1)
                ->where('stats.completed', 0)
                ->where('stats.overdue', 0));
    }

    public function test_department_dashboard_calculates_active_completed_and_overdue_correctly()
    {
        $dept = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $dept->id]);
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $dept->id]);

        $base = ['assigned_by_user_id' => $commissioner->id, 'assigned_to_user_id' => $officer->id, 'department_id' => $dept->id];
        Task::factory()->status(TaskStatus::InProgress)->create($base);
        Task::factory()->overdue()->create($base);
        Task::factory()->status(TaskStatus::Completed)->create($base);
        // Archived assignments count as completed, not active.
        Task::factory()->status(TaskStatus::Archived)->create($base);
        // A completed assignment past its due date is not overdue.
        Task::factory()->status(TaskStatus::Completed)->create([...$base, 'due_date' => now()->subDays(5)->toDateString()]);

        $this->actingAs($commissioner)->get('/department/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 5)
                ->where('stats.active', 2)
                ->where('stats.completed', 3)
                ->where('stats.overdue', 1));
    }

    public function test_delegation_does_not_double_count_department_statistics()
    {
        $dept = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $dept->id]);
        $officer = User::factory()->role(Role::Officer)->create([
            'department_id' => $dept->id,
            'supervisor_user_id' => $commissioner->id,
        ]);

        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $commissioner->id,
            'current_assignee_user_id' => $commissioner->id,
            'department_id' => $dept->id,
        ]);

        app(AssignmentWorkflowService::class)->delegate($commissioner, $task, $officer, [
            'instructions' => 'Handle and report back.',
            'is_direct' => false,
        ]);

        // The delegated assignment stays a single record.
        $this->actingAs($commissioner)->get('/department/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 1)
                ->where('stats.active', 1));
    }

    public function test_officer_dashboard_shows_only_own_tasks_and_upcoming_deadlines()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $supervisor = User::factory()->role(Role::Commissioner)->create();

        Task::factory()->create([
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => $officer->id,
            'due_date' => now()->addDays(3)->toDateString(),
        ]);
        Task::factory()->create([
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => $officer->id,
            'due_date' => now()->addDays(20)->toDateString(),
        ]);
        Task::factory()->create([
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => User::factory()->role(Role::Officer)->create()->id,
        ]);

        $this->actingAs($officer)->get('/my/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboards/officer')
                ->where('stats.total', 2)
                ->count('upcoming', 1));
    }

    public function test_admin_dashboard_reports_user_and_task_counts()
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        User::factory()->role(Role::Officer)->count(3)->create();
        User::factory()->role(Role::Officer)->inactive()->create();

        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboards/admin')
                ->where('stats.total_users', 5)
                ->where('stats.active_users', 4));
    }

    public function test_task_list_respects_officer_scope()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $supervisor = User::factory()->role(Role::Commissioner)->create();

        Task::factory()->create([
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => $officer->id,
        ]);
        Task::factory()->status(TaskStatus::InProgress)->create([
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => User::factory()->role(Role::Officer)->create()->id,
        ]);

        $this->actingAs($officer)->get('/tasks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tasks/index')
                ->where('pageTitle', 'My Tasks')
                ->where('scopedTotal', 1)
                ->count('tasks.data', 1));
    }
}
