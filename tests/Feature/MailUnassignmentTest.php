<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailUnassignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_drawer_exposes_assignment_information_and_unassign_capability(): void
    {
        $department = Department::factory()->create();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'department_id' => $department->id,
            'assigned_to_user_id' => $officer->id,
            'priority' => 'high',
            'due_date' => today()->addDays(7)->toDateString(),
            'instructions' => 'Prepare a full brief for PS review.',
        ])->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        $this->actingAs($clerk)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.assignment.task_id', $task->id)
                ->where('selectedMail.assignment.reference', $task->reference)
                ->where('selectedMail.assignment.is_withdrawn', false)
                ->where('selectedMail.assignment.assigned_officer', $officer->full_name)
                ->where('selectedMail.assignment.assigned_by', $clerk->full_name)
                ->where('selectedMail.assignment.instructions', 'Prepare a full brief for PS review.')
                ->where('selectedMail.assignment.active_assignees.0.user_id', $officer->id)
                ->where('selectedMail.assignment.unassignments', [])
                ->where('selectedMail.can_unassign', true)
                ->where('selectedMail.can_assign', false));
    }

    public function test_full_unassignment_releases_the_mail_for_reassignment_and_preserves_history(): void
    {
        $department = Department::factory()->create();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $replacement = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'department_id' => $department->id,
            'assigned_to_user_id' => $officer->id,
            'priority' => 'high',
            'instructions' => 'Handle the sender request.',
        ])->assertSessionHasNoErrors();
        $task = Task::firstOrFail();

        $this->actingAs($clerk)->post(route('tasks.workflow.unassign', $task), [
            'user_ids' => [$officer->id],
            'reason' => 'Assigned to the wrong officer.',
            'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        // The mail returns to the register while the withdrawn assignment,
        // its forwarding record, and the audit trail remain intact.
        $mail->refresh();
        $this->assertNull($mail->task_id);
        $this->assertSame('registered', $mail->status->value);
        $this->assertSame('unassigned', $task->refresh()->execution_status);
        $this->assertDatabaseHas('mail_records', [
            'source_mail_record_id' => $mail->id,
            'routing_task_id' => $task->id,
        ]);
        $this->assertTrue(AuditLog::query()
            ->where('category', 'mail')
            ->where('target_type', 'MailRecord')
            ->where('target_id', $mail->id)
            ->where('action', 'like', "Assignment {$task->reference} withdrawn%")
            ->exists());

        // The drawer shows the withdrawn assignment and allows re-forwarding.
        $this->actingAs($clerk)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.status_value', 'registered')
                ->where('selectedMail.assignment.task_id', $task->id)
                ->where('selectedMail.assignment.is_withdrawn', true)
                ->where('selectedMail.assignment.status', 'Withdrawn')
                ->where('selectedMail.assignment.unassignments.0.officer', $officer->full_name)
                ->where('selectedMail.assignment.unassignments.0.unassigned_by', $clerk->full_name)
                ->where('selectedMail.assignment.unassignments.0.reason', 'Assigned to the wrong officer.')
                ->where('selectedMail.can_assign', true)
                ->where('selectedMail.can_unassign', false));

        // The withdrawn task still shows its source correspondence.
        $this->actingAs($clerk)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTask.mail_origin.register_number', $mail->register_number));

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'department_id' => $department->id,
            'assigned_to_user_id' => $replacement->id,
            'priority' => 'medium',
            'instructions' => 'Reassigned after withdrawal.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Task::count());
        $newTask = Task::query()->latest('id')->first();
        $this->assertSame($newTask->id, $mail->refresh()->task_id);
        $this->assertSame($replacement->id, $newTask->assigned_to_user_id);
    }

    public function test_partial_unassignment_keeps_the_mail_linked_to_the_active_assignment(): void
    {
        $department = Department::factory()->create();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $first = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $second = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'department_id' => $department->id,
            'target_type' => 'multiple',
            'assigned_to_user_ids' => [$first->id, $second->id],
            'priority' => 'high',
            'instructions' => 'Joint action required.',
        ])->assertSessionHasNoErrors();
        $task = Task::firstOrFail();

        $this->actingAs($clerk)->post(route('tasks.workflow.unassign', $task), [
            'user_ids' => [$first->id],
            'reason' => 'Only one officer should continue.',
            'confirmed' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($task->id, $mail->refresh()->task_id);
        $this->assertNotSame('registered', $mail->status->value);
        $this->assertSame($second->id, $task->refresh()->current_assignee_user_id);
    }
}
