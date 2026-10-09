<?php

namespace Tests\Feature\Tasks;

use App\Enums\Role;
use App\Models\AnnotationTitle;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnnotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_linked_annotation_is_correspondence_with_explicit_office_route(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $task = Task::factory()->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $ps->id,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'task_id' => $task->id,
        ]);
        $origin = AnnotationTitle::create(['shorthand' => 'PS', 'full_title' => 'Permanent Secretary', 'active' => true]);
        $destination = AnnotationTitle::where('normalized_shorthand', 'chrm')->firstOrFail();

        $this->actingAs($ps)->post(route('tasks.annotations.store', $task), [
            'text' => 'Please advise on this letter.',
            'origin_title_id' => $origin->id,
            'recipient_title_id' => $destination->id,
        ])->assertSessionHasNoErrors();

        $entry = CorrespondenceUpdate::where('task_id', $task->id)->where('type', 'annotation')->firstOrFail();
        $this->assertSame('task_annotation', $entry->entry_method);
        $this->assertNotNull($entry->task_history_id);
        $this->assertSame($mail->correspondence_id, $entry->correspondence_id);
        $this->assertSame('PS', $entry->source_name_snapshot);
        $this->assertSame('C/HRM', $entry->destination_office_snapshot);
        $this->assertSame($origin->id, $entry->source_annotation_title_id);
        $this->assertSame($destination->id, $entry->destination_annotation_title_id);

        $detail = $this->get(route('mail.show', [$mail, 'mode' => 'basic']))->assertOk()->inertiaProps('selectedMail');
        $shown = collect($detail['basic_correspondences'])->firstWhere('id', $entry->id);
        $this->assertSame('Please advise on this letter.', $shown['text']);
        $this->assertSame('PS/ES', $shown['from_office']);
        $this->assertSame('C/HRM', $shown['destination_office']);
        $this->assertSame(today()->format('d/m/Y'), $shown['logged_date']);
        $this->assertCount(1, collect($detail['activity_history'])->where('message', 'Please advise on this letter.'));
    }

    public function test_standalone_task_annotation_does_not_create_correspondence(): void
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $task = Task::factory()->create([
            'assigned_by_user_id' => $officer->id,
            'assigned_to_user_id' => $officer->id,
        ]);

        $this->actingAs($officer)->post(route('tasks.annotations.store', $task), ['text' => 'Task-only note'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('task_histories', ['task_id' => $task->id, 'note' => 'Task-only note']);
        $this->assertDatabaseCount('correspondence_updates', 0);
    }

    public function test_historical_annotation_backfill_finds_routing_tasks_and_is_idempotent(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $task = Task::factory()->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => null,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'routing_task_id' => $task->id,
            'recipient_name' => 'Regional Office',
        ]);
        $history = TaskHistory::create([
            'task_id' => $task->id,
            'action_type' => 'Annotated',
            'note' => 'Historical direction',
            'performed_by_user_id' => $ps->id,
            'performed_by_name_snapshot' => $ps->full_name,
            'performed_by_role' => $ps->role->value,
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('mail:backfill-task-annotations')->assertSuccessful();
        $this->artisan('mail:backfill-task-annotations')->assertSuccessful();

        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $mail->correspondence_id,
            'task_history_id' => $history->id,
            'type' => 'annotation',
            'body' => 'Historical direction',
            'destination_office_snapshot' => 'Regional Office',
        ]);
        $this->assertDatabaseCount('correspondence_updates', 1);
    }

    public function test_annotations_show_author_name_role_and_time_in_chronological_order(): void
    {
        $dept = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create([
            'department_id' => $dept->id,
            'full_name' => 'Grace Nakato',
            'title' => 'Commissioner – Human Resources',
        ]);
        $officer = User::factory()->role(Role::Officer)->create([
            'department_id' => $dept->id,
            'title' => 'Senior Human Resource Officer',
        ]);
        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $officer->id,
            'department_id' => $dept->id,
        ]);

        $this->actingAs($commissioner)
            ->post(route('tasks.annotations.store', $task), ['text' => 'First instruction'])
            ->assertSessionHasNoErrors();
        $this->actingAs($officer)
            ->post(route('tasks.annotations.store', $task), ['text' => 'Acknowledged and started'])
            ->assertSessionHasNoErrors();

        $this->actingAs($commissioner)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->count('selectedTask.annotations', 2)
                ->where('selectedTask.annotations.0.text', 'First instruction')
                ->where('selectedTask.annotations.0.author', 'Grace Nakato')
                ->where('selectedTask.annotations.0.author_role', 'Commissioner – Human Resources')
                ->where('selectedTask.annotations.1.text', 'Acknowledged and started')
                ->where('selectedTask.annotations.1.author_role', 'Senior Human Resource Officer'));
    }

    public function test_annotation_line_breaks_and_long_text_are_preserved(): void
    {
        $commissioner = User::factory()->role(Role::Commissioner)->create();
        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $commissioner->id,
        ]);

        $text = "Line one\nLine two\n\nParagraph after a blank line — ".trim(str_repeat('detail ', 200));

        $this->actingAs($commissioner)
            ->post(route('tasks.annotations.store', $task), ['text' => $text])
            ->assertSessionHasNoErrors();

        $this->actingAs($commissioner)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTask.annotations.0.text', $text));
    }

    public function test_annotations_are_only_visible_on_tasks_the_viewer_is_authorised_to_access(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $author = User::factory()->role(Role::Commissioner)->create(['department_id' => $deptA->id]);
        $outsider = User::factory()->role(Role::Commissioner)->create(['department_id' => $deptB->id]);
        $task = Task::factory()->create([
            'assigned_by_user_id' => $author->id,
            'assigned_to_user_id' => $author->id,
            'department_id' => $deptA->id,
        ]);

        $this->actingAs($author)
            ->post(route('tasks.annotations.store', $task), ['text' => 'Internal note'])
            ->assertSessionHasNoErrors();

        // Neither the task view nor the annotation write path is reachable
        // for a user outside the assignment's scope.
        $this->actingAs($outsider)->get(route('tasks.show', $task))->assertForbidden();
        $this->actingAs($outsider)
            ->post(route('tasks.annotations.store', $task), ['text' => 'Should never land'])
            ->assertForbidden();

        // The correspondence feed also never leaks it.
        $this->actingAs($outsider)->get(route('correspondence.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
    }

    public function test_task_with_no_annotations_returns_an_empty_list(): void
    {
        $commissioner = User::factory()->role(Role::Commissioner)->create();
        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $commissioner->id,
        ]);

        $this->actingAs($commissioner)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->count('selectedTask.annotations', 0));
    }
}
