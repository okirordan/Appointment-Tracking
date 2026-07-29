<?php

namespace Tests\Feature;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\Division;
use App\Models\SearchHistory;
use App\Models\Task;
use App\Models\User;
use App\Models\Workstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OversightTest extends TestCase
{
    use RefreshDatabase;

    public function test_ps_and_officer_can_open_their_task_scoped_annotation_feed(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create();

        $this->actingAs($ps)->get(route('correspondence.index'))->assertOk();
        $this->actingAs($officer)->get(route('correspondence.index'))->assertOk();
    }

    public function test_search_respects_officer_scope()
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $supervisor = User::factory()->role(Role::Commissioner)->create();

        Task::factory()->create([
            'title' => 'Curriculum report for me',
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => $officer->id,
        ]);
        Task::factory()->create([
            'title' => 'Curriculum report for someone else',
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => User::factory()->role(Role::Officer)->create()->id,
        ]);

        $this->actingAs($officer)->get('/home?q=curriculum')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->count('results.tasks', 1)
                ->where('results.tasks.0.title', 'Curriculum report for me')
                ->count('results.officers', 0));
    }

    public function test_global_task_search_matches_partial_subject_words(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $subject = Workstream::factory()->create([
            'type' => 'subject',
            'name' => 'Teacher Attendance Monitoring',
        ]);
        $task = Task::factory()->create([
            'title' => 'Prepare the monthly brief',
            'workstream_id' => $subject->id,
        ]);

        $this->actingAs($ps)->get('/home?q=attend&type=tasks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->count('results.tasks', 1)
                ->where('results.tasks.0.id', $task->id)
                ->where('results.tasks.0.workstream_name', 'Teacher Attendance Monitoring'));
    }

    public function test_search_suggests_a_close_title_or_subject_term_and_shows_its_results(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $subject = Workstream::factory()->create([
            'type' => 'subject',
            'name' => 'Teacher Housing',
        ]);
        $task = Task::factory()->create([
            'title' => 'Prepare accommodation brief',
            'workstream_id' => $subject->id,
        ]);

        $this->actingAs($ps)->get('/home?q=Teachers&type=tasks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.total', 0)
                ->where('results.did_you_mean.term', 'teacher')
                ->where('results.did_you_mean.results.total', 1)
                ->count('results.did_you_mean.results.tasks', 1)
                ->where('results.did_you_mean.results.tasks.0.id', $task->id));
    }

    public function test_recent_searches_are_private_and_capped_at_ten()
    {
        $user = User::factory()->role(Role::Ps)->create();
        $other = User::factory()->role(Role::Ps)->create();

        foreach (range(1, 12) as $i) {
            $this->actingAs($user)->get("/home?q=term{$i}");
        }

        $this->assertSame(10, SearchHistory::where('user_id', $user->id)->count());

        $this->actingAs($other)->get('/home')
            ->assertInertia(fn (Assert $page) => $page->count('recentSearches', 0));
    }

    public function test_officer_lookup_respects_department_scope()
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $deptA->id]);
        User::factory()->role(Role::Officer)->create(['department_id' => $deptA->id, 'full_name' => 'Brenda Inside']);
        User::factory()->role(Role::Officer)->create(['department_id' => $deptB->id, 'full_name' => 'Brenda Outside']);

        $this->actingAs($commissioner)->get('/officer-lookup?q=brenda')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('oversight/officer-lookup')
                ->count('officers', 1)
                ->where('officers.0.full_name', 'Brenda Inside'));
    }

    public function test_officer_cannot_access_lookup_or_reports()
    {
        $officer = User::factory()->role(Role::Officer)->create();

        $this->actingAs($officer)->get('/officer-lookup')->assertForbidden();
        $this->actingAs($officer)->get('/reports')->assertForbidden();
        $this->actingAs($officer)->get('/reports/export')->assertForbidden();
    }

    public function test_csv_export_escapes_formula_injection_and_is_audited()
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $assignee = User::factory()->role(Role::Commissioner)->create();

        Task::factory()->level(AssignmentLevel::Ps)->create([
            'title' => '=HYPERLINK("http://evil","x")',
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $response = $this->actingAs($ps)->get('/reports/export');

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('"\'=HYPERLINK', $csv);
        $this->assertDatabaseHas('audit_logs', ['category' => 'report', 'action' => 'Exported task report to CSV']);
    }

    public function test_report_respects_date_range()
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $assignee = User::factory()->role(Role::Commissioner)->create();

        $old = Task::factory()->level(AssignmentLevel::Ps)->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
        $old->timestamps = false;
        $old->forceFill(['created_at' => now()->subMonths(3)])->save();

        Task::factory()->level(AssignmentLevel::Ps)->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $from = now()->subDays(7)->toDateString();

        $this->actingAs($ps)->get("/reports?from={$from}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('oversight/reports')
                ->where('summary.total', 1));
    }

    public function test_report_includes_rich_delivery_metrics_and_clickable_officer_ids(): void
    {
        $department = Department::factory()->create(['name' => 'Planning']);
        $ps = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create([
            'department_id' => $department->id,
            'full_name' => 'Clickable Officer',
            'title' => 'Senior Planner',
        ]);

        Task::factory()->level(AssignmentLevel::Ps)->status(TaskStatus::Completed)->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $officer->id,
            'department_id' => $department->id,
            'due_date' => now()->addDay()->toDateString(),
        ]);
        Task::factory()->level(AssignmentLevel::Ps)->overdue()->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $officer->id,
            'department_id' => $department->id,
            'priority' => 'high',
        ]);

        $this->actingAs($ps)->get('/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 2)
                ->where('summary.active', 1)
                ->where('summary.completion_rate', 50)
                ->where('summary.average_progress', 63)
                ->where('summary.on_time_rate', 100)
                ->where('departments.0.id', $department->id)
                ->where('departments.0.officers.0.officer_id', $officer->id)
                ->where('departments.0.officers.0.title', 'Senior Planner')
                ->count('statusBreakdown', 2)
                ->count('priorityBreakdown', 2));
    }

    public function test_correspondence_shows_department_annotations_grouped_by_officer()
    {
        $dept = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $dept->id]);
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $dept->id]);

        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $officer->id,
            'department_id' => $dept->id,
        ]);

        $this->actingAs($commissioner)->post("/tasks/{$task->id}/annotations", ['text' => 'Please expedite this.']);

        $this->actingAs($commissioner)->get('/correspondence')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('oversight/correspondence')
                ->count('groups', 1)
                ->where('groups.0.officer', $officer->full_name)
                ->where('groups.0.items.0.text', 'Please expedite this.'));
    }

    public function test_performance_page_reports_officer_metrics()
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $supervisor = User::factory()->role(Role::Commissioner)->create();
        $officer = User::factory()->role(Role::Officer)->create(['full_name' => 'Metric Officer']);

        Task::factory()->status(TaskStatus::Completed)->create([
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => $officer->id,
            'due_date' => now()->addDays(2)->toDateString(),
        ]);
        Task::factory()->overdue()->create([
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => $officer->id,
        ]);

        $this->actingAs($ps)->get('/officer-performance?q=metric')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('oversight/officer-performance')
                ->count('rows', 1)
                ->where('rows.0.assigned', 2)
                ->where('rows.0.completed', 1)
                ->where('rows.0.overdue', 1)
                ->where('rows.0.on_time_rate', 100)
                ->where('departmentSummaries.central.assigned', 2)
                ->where('departmentSummaries.central.completed', 1)
                ->where('departmentSummaries.central.completion_rate', 50));
    }

    public function test_performance_can_be_filtered_and_categorized_by_department_and_division(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $supervisor = User::factory()->role(Role::Commissioner)->create();
        $department = Department::factory()->create(['name' => 'Policy Department']);
        $otherDepartment = Department::factory()->create(['name' => 'Finance Department']);
        $division = Division::factory()->create([
            'department_id' => $department->id,
            'name' => 'Planning Division',
        ]);
        $otherDivision = Division::factory()->create([
            'department_id' => $otherDepartment->id,
            'name' => 'Accounts Division',
        ]);
        $matchingOfficer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Matching Officer',
            'department_id' => $department->id,
            'division_id' => $division->id,
        ]);
        $otherOfficer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Other Officer',
            'department_id' => $otherDepartment->id,
            'division_id' => $otherDivision->id,
        ]);

        foreach ([$matchingOfficer, $otherOfficer] as $officer) {
            Task::factory()->create([
                'assigned_by_user_id' => $supervisor->id,
                'assigned_to_user_id' => $officer->id,
            ]);
        }

        $this->actingAs($ps)
            ->get("/officer-performance?department={$department->id}&division={$division->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('oversight/officer-performance')
                ->where('filters.department', (string) $department->id)
                ->where('filters.division', (string) $division->id)
                ->count('rows', 1)
                ->where('rows.0.full_name', 'Matching Officer')
                ->where('rows.0.department_id', $department->id)
                ->where('rows.0.department_name', 'Policy Department')
                ->where('rows.0.division_id', $division->id)
                ->where('rows.0.division_name', 'Planning Division'));
    }

    public function test_old_department_performance_route_is_removed(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();

        $this->actingAs($ps)->get('/performance/departments')->assertNotFound();
    }
}
