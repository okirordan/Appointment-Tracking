<?php

namespace Tests\Feature;

use App\Enums\Role as LegacyRole;
use App\Enums\TaskStatus;
use App\Models\AssignmentSubmission;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProfileChange;
use App\Services\Tasks\AssignmentWorkflowService;
use App\Services\Tasks\TaskService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlexibleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_administrator_can_create_and_edit_a_role_with_permissions(): void
    {
        $admin = User::factory()->role(LegacyRole::Sysadmin)->create();

        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Senior Human Resource Officer',
            'description' => 'Supervises HR delivery.',
            'hierarchy_level' => 60,
            'permissions' => ['assignments.view.scope', 'assignments.delegate'],
        ])->assertRedirect();

        $role = Role::where('name', 'senior-human-resource-officer')->firstOrFail();
        $this->assertTrue($role->hasPermissionTo('assignments.delegate'));

        $this->actingAs($admin)->put(route('admin.roles.update', $role), [
            'name' => 'Senior HR Officer',
            'description' => 'Updated role description.',
            'hierarchy_level' => 55,
            'permissions' => ['assignments.view.scope', 'assignments.delegate', 'assignments.review'],
            'reason' => 'Approved organization review.',
        ])->assertRedirect();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'display_name' => 'Senior HR Officer', 'hierarchy_level' => 55]);
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'Role', 'target_id' => $role->id]);
    }

    public function test_user_profile_changes_soft_deletion_and_restoration_are_historical(): void
    {
        $admin = User::factory()->role(LegacyRole::Sysadmin)->create();
        $user = User::factory()->role(LegacyRole::Officer)->create(['full_name' => 'Original Name']);
        $officerRole = Role::where('name', LegacyRole::Officer->value)->firstOrFail();

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'full_name' => 'Updated Name',
            'title' => 'Senior Officer',
            'role_id' => $officerRole->id,
            'reason' => 'Gazetted name and title change.',
        ])->assertRedirect();

        $this->assertTrue(UserProfileChange::where('user_id', $user->id)->where('field_name', 'full_name')->where('old_value', 'Original Name')->where('new_value', 'Updated Name')->exists());

        $this->actingAs($admin)->delete(route('admin.users.destroy', $user), ['reason' => 'Transferred out of the institution.'])->assertRedirect();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('user_lifecycle_events', ['user_id' => $user->id, 'event_type' => 'soft_deleted']);

        $this->actingAs($admin)->post(route('admin.users.restore', $user->id), ['reason' => 'Transfer was reversed.'])->assertRedirect();
        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('user_lifecycle_events', ['user_id' => $user->id, 'event_type' => 'restored']);
    }

    public function test_administrator_can_configure_positions_reporting_lines_and_acting_appointments(): void
    {
        $admin = User::factory()->role(LegacyRole::Sysadmin)->create();
        $commissioner = User::factory()->role(LegacyRole::Commissioner)->create();
        $officer = User::factory()->role(LegacyRole::Officer)->create();
        $commissionerRole = Role::where('name', LegacyRole::Commissioner->value)->firstOrFail();
        $officerRole = Role::where('name', LegacyRole::Officer->value)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.hierarchy.units.store'), ['name' => 'Policy Directorate', 'code' => 'POL', 'type' => 'directorate'])->assertRedirect();
        $unit = OrganizationalUnit::where('code', 'POL')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.hierarchy.positions.store'), [
            'title' => 'Commissioner, Policy', 'organizational_unit_id' => $unit->id, 'role_id' => $commissionerRole->id,
            'hierarchy_level' => 20, 'workflow_capabilities' => ['assign', 'review', 'approve'],
        ])->assertRedirect();
        $supervisorPosition = Position::where('title', 'Commissioner, Policy')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.hierarchy.positions.store'), [
            'title' => 'Policy Officer', 'organizational_unit_id' => $unit->id, 'role_id' => $officerRole->id,
            'supervisor_position_id' => $supervisorPosition->id, 'hierarchy_level' => 100, 'workflow_capabilities' => ['assign'],
        ])->assertRedirect();
        $officerPosition = Position::where('title', 'Policy Officer')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.hierarchy.appointments.store'), [
            'user_id' => $officer->id, 'position_id' => $officerPosition->id, 'supervisor_user_id' => $commissioner->id,
            'is_acting' => true, 'acting_for_user_id' => $commissioner->id, 'starts_at' => now(), 'ends_at' => now()->addWeek(),
        ])->assertRedirect();

        $this->assertDatabaseHas('user_positions', ['user_id' => $officer->id, 'position_id' => $officerPosition->id, 'supervisor_user_id' => $commissioner->id, 'is_acting' => true]);
        $this->assertSame($commissioner->id, $officer->fresh()->supervisor_user_id);
    }

    public function test_cascading_assignment_uses_one_task_and_reports_back_over_the_actual_route(): void
    {
        $ps = User::factory()->role(LegacyRole::Ps)->create();
        $commissioner = User::factory()->role(LegacyRole::Commissioner)->create();
        $principal = User::factory()->role(LegacyRole::Commissioner)->create();
        $officer = User::factory()->role(LegacyRole::Officer)->create();
        $tasks = app(TaskService::class);
        $workflow = app(AssignmentWorkflowService::class);

        $task = $tasks->create($ps, ['title' => 'Prepare Cabinet brief', 'description' => null, 'assigned_to_user_id' => $commissioner->id, 'priority' => 'high', 'due_date' => now()->addWeek()->toDateString(), 'instructions' => 'Coordinate the response.']);
        $workflow->delegate($commissioner, $task, $principal, ['instructions' => 'Consolidate technical input.', 'is_direct' => false]);
        $workflow->delegate($principal, $task->refresh(), $officer, ['instructions' => 'Draft the technical brief.', 'is_direct' => false]);

        $this->assertSame(1, Task::count());
        $this->assertCount(3, $task->fresh()->workflowSteps);

        $submission = $workflow->submit($officer, $task->refresh(), 'Draft completed and checked.');
        $workflow->review($principal, $submission, ['decision' => 'approve', 'comments' => 'Technically cleared.']);
        $commissionerSubmission = AssignmentSubmission::where('task_id', $task->id)->where('status', 'pending_review')->latest('id')->firstOrFail();
        $workflow->review($commissioner, $commissionerSubmission, ['decision' => 'approve', 'comments' => 'Approved for PS.']);
        $psSubmission = AssignmentSubmission::where('task_id', $task->id)->where('status', 'pending_review')->latest('id')->firstOrFail();
        $workflow->review($ps, $psSubmission, ['decision' => 'approve', 'comments' => 'Final approval.']);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->workflow_status);
        $this->assertSame('approved', $task->approval_status);
        $this->assertSame(100, $task->progress_percent);
    }

    public function test_direct_assignment_skips_levels_and_returns_directly_to_sender(): void
    {
        $commissioner = User::factory()->role(LegacyRole::Commissioner)->create();
        $officer = User::factory()->role(LegacyRole::Officer)->create();
        $task = app(TaskService::class)->create($commissioner, ['title' => 'Urgent direct brief', 'description' => null, 'assigned_to_user_id' => $officer->id, 'priority' => 'urgent', 'due_date' => now()->addDay()->toDateString(), 'instructions' => 'Report directly to me.']);

        $submission = app(AssignmentWorkflowService::class)->submit($officer, $task, 'Direct brief submitted.');
        $this->assertSame($commissioner->id, $task->fresh()->current_reviewer_user_id);
        $this->assertSame(1, $task->workflowSteps()->count());

        app(AssignmentWorkflowService::class)->review($commissioner, $submission, ['decision' => 'return', 'comments' => 'Add the financial implication.', 'revised_due_at' => now()->addDays(2)]);
        $this->assertSame($officer->id, $task->fresh()->current_assignee_user_id);
        $this->assertSame('returned', $task->fresh()->review_status);
    }
}
