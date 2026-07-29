<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\Division;
use App\Models\ImportBatch;
use App\Models\Task;
use App\Models\User;
use App\Models\Workstream;
use App\Services\Performance\PerformanceExplanationService;
use App\Services\Performance\PerformanceMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntegratedExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_division_must_belong_to_selected_department(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $department = Department::factory()->create();
        $other = Department::factory()->create();
        $division = Division::factory()->create(['department_id' => $other->id]);
        $this->actingAs($admin)->post(route('admin.users.store'), ['full_name' => 'Scoped Officer', 'title' => 'Officer', 'role' => 'officer', 'department_id' => $department->id, 'division_id' => $division->id, 'username' => 'scoped.officer'])->assertSessionHasErrors('division_id');
    }

    public function test_officer_search_exposes_only_own_task_associated_workstream(): void
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $visible = Workstream::factory()->create(['name' => 'Teacher Growth Programme']);
        $hidden = Workstream::factory()->create(['name' => 'Teacher Secret Programme']);
        Task::factory()->create(['assigned_to_user_id' => $officer->id, 'workstream_id' => $visible->id, 'title' => 'Visible teacher task']);
        Task::factory()->create(['workstream_id' => $hidden->id, 'title' => 'Hidden teacher task']);
        $this->actingAs($officer)->get('/home?q=Teacher&type=all')->assertInertia(fn (Assert $page) => $page->where('results.workstreams.0.id', $visible->id)->where('results.tasks.0.title', 'Visible teacher task')->where('results.officers', []));
    }

    public function test_officer_fuzzy_search_suggestions_remain_task_scoped(): void
    {
        $officer = User::factory()->role(Role::Officer)->create();
        $visible = Workstream::factory()->create(['name' => 'Teacher Growth Programme']);
        $hidden = Workstream::factory()->create(['name' => 'Teacher Secret Programme']);
        $visibleTask = Task::factory()->create([
            'assigned_to_user_id' => $officer->id,
            'workstream_id' => $visible->id,
            'title' => 'Visible teacher task',
        ]);
        Task::factory()->create([
            'workstream_id' => $hidden->id,
            'title' => 'Hidden teacher task',
        ]);

        $this->actingAs($officer)->get('/home?q=Teachers&type=all')
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.did_you_mean.term', 'teacher')
                ->count('results.did_you_mean.results.tasks', 1)
                ->where('results.did_you_mean.results.tasks.0.id', $visibleTask->id)
                ->count('results.did_you_mean.results.workstreams', 1)
                ->where('results.did_you_mean.results.workstreams.0.id', $visible->id));
    }

    public function test_officer_performance_is_department_scoped_for_commissioners(): void
    {
        $own = Department::factory()->create();
        $other = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $own->id]);
        $ownOfficer = User::factory()->role(Role::Officer)->create(['department_id' => $own->id]);
        $otherOfficer = User::factory()->role(Role::Officer)->create(['department_id' => $other->id]);
        Task::factory()->create(['assigned_to_user_id' => $ownOfficer->id]);
        Task::factory()->create(['assigned_to_user_id' => $otherOfficer->id]);

        $this->actingAs($commissioner)->get(route('performance.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->count('rows', 1)
                ->where('rows.0.id', $ownOfficer->id)
                ->count('departmentOptions', 1)
                ->where('departmentOptions.0.id', $own->id));

        $this->actingAs($commissioner)->get(route('performance.show', $otherOfficer))->assertForbidden();
    }

    public function test_metrics_and_explanations_handle_minimum_sample_and_on_time_rate(): void
    {
        $officer = User::factory()->create();
        Task::factory()->count(4)->status(TaskStatus::Completed)->create(['assigned_to_user_id' => $officer->id, 'due_date' => now()->addDay(), 'completed_at' => now()]);
        Task::factory()->overdue()->create(['assigned_to_user_id' => $officer->id, 'priority' => 'high']);
        $metrics = app(PerformanceMetricsService::class)->calculate(Task::where('assigned_to_user_id', $officer->id));
        $this->assertSame(5, $metrics['assigned']);
        $this->assertSame(4, $metrics['completed']);
        $this->assertSame(80.0, $metrics['completion_rate']);
        $this->assertSame(100.0, $metrics['on_time_rate']);
        $this->assertTrue($metrics['eligible_for_rank']);
        $explanations = app(PerformanceExplanationService::class)->explain($metrics, 60.0);
        $this->assertStringContainsString('peer average', $explanations[0]['text']);
    }

    public function test_csv_import_is_staged_before_confirmation_and_is_idempotent_by_source_id(): void
    {
        Storage::fake('local');
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $csv = "external_id,code,name,active\nD1,BSE,Basic Education,true\n";
        $this->actingAs($admin)->post(route('admin.imports.store'), ['source_system' => 'Legacy Access', 'entity_type' => 'departments', 'file' => UploadedFile::fake()->createWithContent('departments.csv', $csv)])->assertRedirect();
        $this->assertDatabaseMissing('departments', ['code' => 'BSE']);
        $batch = ImportBatch::firstOrFail();
        $this->actingAs($admin)->post(route('admin.imports.confirm', $batch))->assertRedirect();
        $this->assertDatabaseHas('departments', ['code' => 'BSE', 'external_id' => 'Legacy Access:D1']);
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'ImportBatch', 'target_id' => $batch->id]);
    }
}
