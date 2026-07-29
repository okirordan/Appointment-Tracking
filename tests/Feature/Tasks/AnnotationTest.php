<?php

namespace Tests\Feature\Tasks;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnnotationTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertInertia(fn (Assert $page) => $page->count('groups', 0));
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
