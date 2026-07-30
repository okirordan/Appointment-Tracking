<?php

namespace Tests\Feature\Tasks;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskUnassignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_assigning_officer_can_unassign_without_deleting_history_and_user_loses_active_access(): void
    {
        $department = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);

        $this->actingAs($commissioner)->post(route('tasks.store'), [
            'title' => 'Prepare departmental response',
            'description' => 'Review the attached brief.',
            'assigned_to_user_id' => $officer->id,
            'priority' => 'high',
        ])->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        TaskHistory::create([
            'task_id' => $task->id,
            'action_type' => 'Annotated',
            'note' => 'Work already discussed with the technical team.',
            'status' => 'assigned',
            'progress_percent' => 0,
            'performed_by_user_id' => $officer->id,
            'performed_by_name_snapshot' => $officer->full_name,
            'performed_by_title_snapshot' => $officer->title,
            'performed_by_role' => $officer->roleName(),
            'created_at' => now(),
        ]);

        $this->actingAs($commissioner)->post(route('tasks.workflow.unassign', $task), [
            'user_ids' => [$officer->id],
            'reason' => 'The work has moved to another technical unit.',
            'comments' => 'Reassignment will follow after the revised scope is approved.',
            'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertNull($task->assigned_to_user_id);
        $this->assertNull($task->current_assignee_user_id);
        $this->assertSame('pending', $task->workflow_status->value);
        $this->assertSame('unassigned', $task->execution_status);
        $this->assertDatabaseHas('task_unassignments', [
            'task_id' => $task->id,
            'user_id' => $officer->id,
            'unassigned_by_user_id' => $commissioner->id,
            'reason' => 'The work has moved to another technical unit.',
            'status_before' => 'assigned',
            'status_after' => 'pending',
        ]);
        $this->assertDatabaseHas('assignment_participants', [
            'task_id' => $task->id,
            'user_id' => $officer->id,
            'participant_type' => 'assignee',
            'active' => false,
        ]);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'action_type' => 'Annotated',
            'note' => 'Work already discussed with the technical team.',
        ]);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'action_type' => 'Unassigned',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'task',
            'target_id' => $task->id,
            'actor_user_id' => $commissioner->id,
        ]);
        $this->assertTrue(Notification::where('user_id', $officer->id)->where('type', 'task_unassigned')->exists());

        $this->actingAs($officer)->get(route('tasks.show', $task))->assertForbidden();
        $this->actingAs($officer)->post(route('tasks.progress.store', $task), [
            'status' => 'in_progress',
            'progress' => 25,
            'note' => 'This update must be rejected.',
        ])->assertForbidden();
        $this->actingAs($commissioner)->get(route('tasks.show', $task))->assertOk();
    }

    public function test_unassignment_requires_a_reason_selection_and_confirmation(): void
    {
        $commissioner = User::factory()->role(Role::Commissioner)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'creator_user_id' => $commissioner->id,
            'owner_user_id' => $commissioner->id,
            'assigned_to_user_id' => $officer->id,
            'current_assignee_user_id' => $officer->id,
        ]);

        $this->actingAs($commissioner)->post(route('tasks.workflow.unassign', $task), [
            'user_ids' => [],
            'reason' => '',
            'confirmed' => false,
        ])->assertSessionHasErrors(['user_ids', 'reason', 'confirmed']);

        $this->assertSame($officer->id, $task->refresh()->assigned_to_user_id);
        $this->assertDatabaseCount('task_unassignments', 0);
    }
}
