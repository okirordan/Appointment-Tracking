<?php

namespace Tests\Feature\Tasks;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Models\Workstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ps_creates_ps_level_assignment_with_generated_reference()
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $dept = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $dept->id]);

        $response = $this->actingAs($ps)->post('/tasks', [
            'title' => 'Cabinet brief',
            'assigned_to_user_id' => $commissioner->id,
            'priority' => 'high',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $response->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        $year = now()->year;

        $response->assertRedirect(route('tasks.show', $task, absolute: false));
        $this->assertSame("PS-{$year}-001", $task->reference);
        $this->assertSame('assigned', $task->workflow_status->value);
        $this->assertSame(0, $task->progress_percent);
        $this->assertSame($commissioner->full_name, $task->assigned_to_name_snapshot);

        // TASK-CRT-006/007: audited and assignee notified.
        $this->assertDatabaseHas('audit_logs', ['category' => 'task', 'target_id' => $task->id]);
        $this->assertSame(1, Notification::where('user_id', $commissioner->id)->count());
    }

    public function test_references_increment_per_prefix()
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create();

        foreach (range(1, 3) as $i) {
            $this->actingAs($ps)->post('/tasks', [
                'title' => "Task {$i}",
                'assigned_to_user_id' => $commissioner->id,
                'priority' => 'medium',
            ]);
        }

        $year = now()->year;
        $this->assertSame(
            ["PS-{$year}-001", "PS-{$year}-002", "PS-{$year}-003"],
            Task::orderBy('id')->pluck('reference')->all(),
        );
    }

    public function test_commissioner_reference_uses_department_code()
    {
        $dept = Department::factory()->create(['code' => 'BSE']);
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $dept->id]);
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $dept->id]);

        $this->actingAs($commissioner)->post('/tasks', [
            'title' => 'Verify records',
            'assigned_to_user_id' => $officer->id,
            'priority' => 'medium',
        ]);

        $this->assertSame('BSE-'.now()->year.'-001', Task::firstOrFail()->reference);
    }

    public function test_commissioner_can_find_and_assign_eligible_officers_across_the_organisation()
    {
        $deptA = Department::factory()->create(['code' => 'OWN']);
        $deptB = Department::factory()->create(['code' => 'EXT']);
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $deptA->id]);
        $outsideOfficer = User::factory()->role(Role::Officer)->create(['full_name' => 'External Department Officer', 'department_id' => $deptB->id]);

        $this->actingAs($commissioner)->getJson(route('tasks.assignee-search', ['q' => 'External Department']))
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $outsideOfficer->id);

        $response = $this->actingAs($commissioner)->post('/tasks', [
            'title' => 'Cross-department task',
            'assigned_to_user_id' => $outsideOfficer->id,
            'priority' => 'medium',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', [
            'title' => 'Cross-department task',
            'assigned_to_user_id' => $outsideOfficer->id,
            'department_id' => $deptB->id,
        ]);
    }

    public function test_officer_cannot_create_tasks()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $other = User::factory()->role(Role::Officer)->create();

        $this->actingAs($officer)->post('/tasks', [
            'title' => 'Not allowed',
            'assigned_to_user_id' => $other->id,
            'priority' => 'low',
        ])->assertForbidden();
    }

    public function test_title_and_assignee_are_mandatory()
    {
        $ps = User::factory()->role(Role::Ps)->create();

        $this->actingAs($ps)->post('/tasks', ['priority' => 'low'])
            ->assertSessionHasErrors(['title', 'assigned_to_user_id']);
    }

    public function test_task_detail_accepts_legacy_session_filters_without_workstream_key(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $task = Task::factory()->level(AssignmentLevel::Ps)->create([
            'assigned_by_user_id' => $ps->id,
        ]);

        $this->actingAs($ps)
            ->withSession(['tasks.filters' => [
                'q' => '',
                'status' => '',
                'priority' => '',
                'department' => '',
            ]])
            ->get(route('tasks.show', $task))
            ->assertOk();
    }

    public function test_assigning_officer_can_create_a_system_wide_workstream(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();

        $response = $this->actingAs($ps)->post(route('workstreams.store'), [
            'type' => 'programme',
            'name' => 'Teacher Effectiveness Programme',
            'code' => 'tep',
            'description' => 'Improves classroom delivery.',
        ]);

        $workstream = Workstream::firstOrFail();
        $response->assertRedirect()->assertSessionHas('created_workstream_id', $workstream->id);
        $this->assertSame('teacher effectiveness programme', $workstream->normalized_name);
        $this->assertSame('TEP', $workstream->code);
        $this->assertNull($workstream->department_id);

        $commissioner = User::factory()->role(Role::Commissioner)->create();
        $this->actingAs($commissioner)->get(route('tasks.index'))
            ->assertInertia(fn ($page) => $page
                ->where('workstreamOptions.0.id', $workstream->id)
                ->where('workstreamOptions.0.name', 'Teacher Effectiveness Programme'));
    }

    public function test_workstream_names_cannot_be_duplicated_by_case_or_spacing(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();

        $this->actingAs($ps)->post(route('workstreams.store'), [
            'type' => 'project',
            'name' => 'Digital Learning Project',
        ]);
        $existing = Workstream::firstOrFail();

        $this->actingAs($ps)->post(route('workstreams.store'), [
            'type' => 'initiative',
            'name' => '  digital   LEARNING project  ',
        ])->assertSessionHas('created_workstream_id', $existing->id);

        $this->assertSame(1, Workstream::withTrashed()->count());
    }

    public function test_officer_cannot_create_a_shared_workstream(): void
    {
        $officer = User::factory()->role(Role::Officer)->create();

        $this->actingAs($officer)->post(route('workstreams.store'), [
            'type' => 'subject',
            'name' => 'Restricted subject',
        ])->assertForbidden();
    }
}
