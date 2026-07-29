<?php

namespace Tests\Feature\Tasks;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Models\Workstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ps_can_view_a_department_task_created_by_another_user(): void
    {
        $department = Department::factory()->create();
        $ps = User::factory()->role(Role::Ps)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create([
            'department_id' => $department->id,
        ]);
        $esther = User::factory()->role(Role::Officer)->create([
            'department_id' => $department->id,
            'full_name' => 'Esther Nabirye',
        ]);
        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $esther->id,
            'department_id' => $department->id,
        ]);

        $this->actingAs($ps)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tasks/index')
                ->where('pageTitle', 'All Assignments')
                ->where('selectedTask.id', $task->id)
                ->where('selectedTask.assigned_to_user_id', $esther->id)
                ->where('selectedTask.can_update_progress', false)
                ->where('selectedTask.can_annotate', true));
    }

    public function test_clerk_scope_remains_limited_for_department_tasks(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create();
        $esther = User::factory()->role(Role::Officer)->create(['full_name' => 'Esther Nabirye']);
        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $esther->id,
        ]);

        $this->actingAs($clerk)->get(route('tasks.show', $task))->assertForbidden();
    }

    public function test_task_list_search_matches_partial_words_in_titles_and_subjects(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $subject = Workstream::factory()->create([
            'type' => 'subject',
            'name' => 'School Feeding Review',
        ]);

        $titleMatch = Task::factory()->create([
            'title' => 'Quarterly Curriculum Analysis',
        ]);
        $subjectMatch = Task::factory()->create([
            'title' => 'Prepare implementation brief',
            'workstream_id' => $subject->id,
        ]);
        $crossFieldMatch = Task::factory()->create([
            'title' => 'Quarterly implementation update',
            'workstream_id' => $subject->id,
        ]);
        Task::factory()->create([
            'title' => 'Unrelated infrastructure inspection',
        ]);

        $this->actingAs($ps)->get(route('tasks.index', ['q' => 'curric']))
            ->assertInertia(fn (Assert $page) => $page
                ->count('tasks.data', 1)
                ->where('tasks.data.0.id', $titleMatch->id));

        $this->actingAs($ps)->get(route('tasks.index', ['q' => 'feed']))
            ->assertInertia(fn (Assert $page) => $page
                ->count('tasks.data', 2)
                ->where('tasks.data', fn ($tasks) => $tasks->pluck('id')->contains($subjectMatch->id)
                    && $tasks->pluck('id')->contains($crossFieldMatch->id)));

        $this->actingAs($ps)->get(route('tasks.index', ['q' => 'quarter feed']))
            ->assertInertia(fn (Assert $page) => $page
                ->count('tasks.data', 1)
                ->where('tasks.data.0.id', $crossFieldMatch->id));
    }
}
