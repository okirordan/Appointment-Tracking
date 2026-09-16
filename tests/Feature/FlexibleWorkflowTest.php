<?php

namespace Tests\Feature;

use App\Enums\Role as LegacyRole;
use App\Enums\TaskStatus;
use App\Models\AssignmentSubmission;
use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProfileChange;
use App\Services\Tasks\AssignmentWorkflowService;
use App\Services\Tasks\TaskService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Validation\ValidationException;
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
        $office = OrganizationalUnit::create(['name' => 'Staff Office', 'code' => 'STAFF-OFFICE', 'type' => 'office', 'active' => true]);
        $user = User::factory()->role(LegacyRole::Officer)->create([
            'full_name' => 'Original Name',
            'organizational_unit_id' => $office->id,
        ]);
        $officerRole = Role::where('name', LegacyRole::Officer->value)->firstOrFail();

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'full_name' => 'Updated Name',
            'title' => 'Senior Officer',
            'role_id' => $officerRole->id,
            'organizational_unit_id' => $office->id,
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

    public function test_legacy_position_reporting_configuration_is_not_exposed(): void
    {
        $this->assertFalse(RouteFacade::has('admin.hierarchy.units.store'));
        $this->assertFalse(RouteFacade::has('admin.hierarchy.positions.store'));
        $this->assertFalse(RouteFacade::has('admin.hierarchy.positions.update'));
        $this->assertFalse(RouteFacade::has('admin.hierarchy.appointments.store'));
        $this->assertTrue(RouteFacade::has('admin.organization-structure.entities.store'));
        $this->assertTrue(RouteFacade::has('admin.organization-structure.entities.move'));
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

    public function test_shared_assignment_waits_for_every_officer_and_preserves_original_titles(): void
    {
        $ps = User::factory()->role(LegacyRole::Ps)->create();
        $first = User::factory()->create(['title' => 'Information Technology Officer']);
        $second = User::factory()->create(['title' => 'Senior Information Technology Officer']);
        $task = app(TaskService::class)->create($ps, ['title' => 'Shared ICT report', 'target_type' => 'multiple', 'assigned_to_user_ids' => [$first->id, $second->id], 'priority' => 'medium']);
        $first->update(['title' => 'Principal Officer']);
        $this->assertSame('Information Technology Officer', $task->workflowSteps()->where('recipient_user_id', $first->id)->first()->recipient_title_snapshot);
        $workflow = app(AssignmentWorkflowService::class);
        $submission = $workflow->submit($first, $task, 'First contribution.');
        $workflow->review($ps, $submission, ['decision' => 'approve', 'comments' => 'Accepted.']);
        $this->assertNotSame(TaskStatus::Completed, $task->fresh()->workflow_status);
        $submission = $workflow->submit($second, $task->fresh(), 'Second contribution.');
        $workflow->review($ps, $submission, ['decision' => 'approve', 'comments' => 'Accepted.']);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->workflow_status);
        $this->assertSame(1, Task::count());
    }

    public function test_delegation_to_two_officers_preserves_each_identity_and_waits_for_both(): void
    {
        $ps = User::factory()->role(LegacyRole::Ps)->create();
        $commissioner = User::factory()->role(LegacyRole::Commissioner)->create();
        $first = User::factory()->create(['title' => 'Information Technology Officer']);
        $second = User::factory()->create(['title' => 'Senior Information Technology Officer']);
        $task = app(TaskService::class)->create($ps, ['title' => 'Joint technical input', 'assigned_to_user_id' => $commissioner->id, 'priority' => 'medium']);
        $this->actingAs($commissioner)->post(route('tasks.workflow.delegate', $task), [
            'recipient_user_ids' => [$first->id, $second->id], 'instructions' => 'Provide your respective contributions.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, $task->workflowSteps()->where('is_current', true)->count());
        $this->assertSame($second->title, $task->workflowSteps()->where('recipient_user_id', $second->id)->first()->recipient_title_snapshot);
        $workflow = app(AssignmentWorkflowService::class);
        $workflow->review($commissioner, $workflow->submit($first, $task->fresh(), 'First input.'), ['decision' => 'approve', 'comments' => 'Accepted.']);
        $this->assertSame(0, $task->submissions()->where('submitted_by_user_id', $commissioner->id)->count());
        $workflow->review($commissioner, $workflow->submit($second, $task->fresh(), 'Second input.'), ['decision' => 'approve', 'comments' => 'Accepted.']);
        $this->assertSame(1, $task->submissions()->where('submitted_by_user_id', $commissioner->id)->where('status', 'pending_review')->count());
        $this->assertSame(1, Task::count());
    }

    public function test_reassignment_keeps_the_previous_officer_and_position_in_history(): void
    {
        $ps = User::factory()->role(LegacyRole::Ps)->create();
        $first = User::factory()->create(['title' => 'Information Technology Officer']);
        $replacement = User::factory()->create(['title' => 'Senior Information Technology Officer']);
        $task = app(TaskService::class)->create($ps, ['title' => 'Reassigned report', 'assigned_to_user_id' => $first->id, 'priority' => 'medium']);
        $original = $task->workflowSteps()->first();
        $new = app(AssignmentWorkflowService::class)->reassign($ps, $task, $replacement, 'Redistribution of duties.');
        $this->assertSame($first->id, $original->fresh()->recipient_user_id);
        $this->assertSame($first->title, $original->fresh()->recipient_title_snapshot);
        $this->assertFalse($original->fresh()->is_current);
        $this->assertSame($replacement->id, $new->recipient_user_id);
        $this->assertSame($replacement->title, $new->recipient_title_snapshot);
        $this->assertSame(2, $task->workflowSteps()->count());
        $this->assertDatabaseHas('assignment_participants', ['task_id' => $task->id, 'user_id' => $first->id, 'active' => false]);
    }

    public function test_progress_and_reassignment_only_change_the_selected_officers_responsibility(): void
    {
        $ps = User::factory()->role(LegacyRole::Ps)->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $replacements = User::factory()->count(2)->create();
        $task = app(TaskService::class)->create($ps, ['title' => 'Joint work', 'target_type' => 'multiple', 'assigned_to_user_ids' => [$first->id, $second->id], 'priority' => 'medium']);
        app(TaskService::class)->updateProgress($first, $task, ['status' => TaskStatus::InProgress->value, 'progress' => 80, 'note' => 'Draft nearly ready.']);
        $this->assertSame(40, $task->fresh()->progress_percent);
        $this->assertSame(0, (int) $task->workflowSteps()->where('recipient_user_id', $second->id)->first()->progress_percent);
        $this->actingAs($ps)->post(route('tasks.workflow.reassign', $task), [
            'from_user_id' => $first->id, 'replacement_user_ids' => $replacements->modelKeys(), 'reason' => 'Redistribution of duties.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEqualsCanonicalizing([$second->id, ...$replacements->modelKeys()], $task->workflowSteps()->where('is_current', true)->pluck('recipient_user_id')->all());
        $this->assertSame(1, Task::count());
    }

    public function test_named_instruction_recipients_keep_ids_and_titles_without_granting_new_access(): void
    {
        $ps = User::factory()->role(LegacyRole::Ps)->create();
        $officers = User::factory()->count(2)->create(['title' => 'Information Technology Officer']);
        $outsider = User::factory()->create();
        $task = app(TaskService::class)->create($ps, ['title' => 'Shared instruction', 'target_type' => 'multiple', 'assigned_to_user_ids' => $officers->modelKeys(), 'priority' => 'medium']);
        $this->actingAs($ps)->post(route('tasks.annotations.store', $task), [
            'text' => 'Please consolidate your findings.', 'origin_user_id' => $ps->id, 'recipient_user_ids' => $officers->modelKeys(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $history = $task->histories()->where('action_type', 'Annotated')->firstOrFail();
        $this->assertEqualsCanonicalizing($officers->modelKeys(), $history->annotation_recipient_user_ids);
        $officers[0]->update(['title' => 'Principal Officer']);
        $this->assertStringContainsString('Information Technology Officer', $history->fresh()->annotation_recipient_snapshot);
        $this->actingAs($ps)->post(route('tasks.annotations.store', $task), [
            'text' => 'Do not expose this task.', 'recipient_user_ids' => [$outsider->id],
        ])->assertSessionHasErrors('recipient_user_ids');
    }

    public function test_withdrawal_can_reassign_to_several_officers_on_one_task(): void
    {
        $ps = User::factory()->role(LegacyRole::Ps)->create();
        $first = User::factory()->create();
        $replacements = User::factory()->count(2)->create();
        $task = app(TaskService::class)->create($ps, ['title' => 'Redistributed work', 'assigned_to_user_id' => $first->id, 'priority' => 'medium']);
        $this->actingAs($ps)->post(route('tasks.workflow.unassign', $task), [
            'user_ids' => [$first->id], 'reason' => 'Redistribution of duties.', 'resolution' => 'reassign',
            'replacement_user_ids' => $replacements->modelKeys(), 'confirmed' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEqualsCanonicalizing($replacements->modelKeys(), $task->workflowSteps()->where('is_current', true)->pluck('recipient_user_id')->all());
        $this->assertSame(1, Task::count());
    }

    public function test_shared_assignment_cannot_be_closed_through_one_officers_progress_update(): void
    {
        $ps = User::factory()->role(LegacyRole::Ps)->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $task = app(TaskService::class)->create($ps, ['title' => 'Joint report', 'target_type' => 'multiple', 'assigned_to_user_ids' => [$first->id, $second->id], 'priority' => 'medium']);
        $this->expectException(ValidationException::class);
        app(TaskService::class)->updateProgress($first, $task, ['status' => TaskStatus::Completed->value, 'progress' => 100, 'note' => 'My contribution is ready.']);
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
