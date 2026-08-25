<?php

namespace Tests\Feature\Tasks;

use App\Enums\Role;
use App\Models\AssignmentWorkflowStep;
use App\Models\Department;
use App\Models\OrganizationalUnit;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DepartmentSecretaryAssignmentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_department_secretary_sees_department_assignment_and_complete_forwarding_route(): void
    {
        [$task, $secretary, $commissioner, $ps, , , $outsideSecretary] = $this->departmentAssignment();

        $this->actingAs($secretary)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTask.department_support.department_name', 'Libraries, E-Learning and Information Technology')
                ->where('selectedTask.department_support.current_officer_name', $commissioner->full_name)
                ->where('selectedTask.can_update_progress', true)
                ->where('selectedTask.can_delegate', true)
                ->where('selectedTask.workflow_route.0.sender_name', $ps->full_name)
                ->where('selectedTask.workflow_route.0.recipient_name', $commissioner->full_name));

        $this->actingAs($outsideSecretary)->get(route('tasks.show', $task))->assertForbidden();
    }

    public function test_department_secretary_records_attributed_progress_on_behalf_of_current_officer(): void
    {
        [$task, $secretary, $commissioner] = $this->departmentAssignment();

        $this->actingAs($secretary)->post(route('tasks.progress.store', $task), [
            'status' => 'in_progress',
            'progress' => 35,
            'note' => 'The implementation team has completed the first review.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'performed_by_user_id' => $secretary->id,
            'performed_by_name_snapshot' => $secretary->full_name,
            'on_behalf_of_user_id' => $commissioner->id,
            'on_behalf_of_name_snapshot' => $commissioner->full_name,
            'note' => 'The implementation team has completed the first review.',
        ]);
        $this->assertSame(35, $task->refresh()->progress_percent);
    }

    public function test_department_secretary_delegates_only_to_officers_in_the_supported_department(): void
    {
        [$task, $secretary, , , $departmentOfficer, $outsideOfficer] = $this->departmentAssignment();

        $this->actingAs($secretary)->getJson(route('tasks.assignee-search', [
            'q' => 'Department Officer',
            'department_only' => 1,
        ]))->assertOk()->assertJsonPath('users.0.id', $departmentOfficer->id);

        $this->actingAs($secretary)->getJson(route('tasks.assignee-search', [
            'q' => 'Outside Officer',
            'department_only' => 1,
        ]))->assertOk()->assertJsonCount(0, 'users');

        $this->assertDatabaseHas('users', [
            'id' => $departmentOfficer->id,
            'active' => true,
            'locked' => false,
            'deleted_at' => null,
        ]);

        $this->actingAs($secretary)->post(route('tasks.workflow.delegate', $task), [
            'recipient_user_id' => $departmentOfficer->id,
            'instructions' => 'Prepare the departmental implementation brief.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('assignment_workflow_steps', [
            'task_id' => $task->id,
            'sender_user_id' => $secretary->id,
            'recipient_user_id' => $departmentOfficer->id,
            'is_current' => true,
        ]);
        $this->assertSame($departmentOfficer->id, $task->refresh()->current_assignee_user_id);

        $this->actingAs($secretary)->post(route('tasks.workflow.delegate', $task), [
            'recipient_user_id' => $outsideOfficer->id,
            'instructions' => 'This must remain outside the permitted department scope.',
        ])->assertForbidden();
    }

    /** @return array{Task, User, User, User, User, User, User} */
    private function departmentAssignment(): array
    {
        $department = Department::factory()->create([
            'name' => 'Libraries, E-Learning and Information Technology',
            'code' => 'LEIT',
        ]);
        $outsideDepartment = Department::factory()->create(['name' => 'Basic Education', 'code' => 'BE']);
        $unit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'LEIT Department',
            'code' => 'LEIT-OFFICE',
            'active' => true,
        ]);
        $ps = User::factory()->role(Role::Ps)->create(['full_name' => 'Permanent Secretary']);
        $commissioner = User::factory()->role(Role::Commissioner)->create([
            'full_name' => 'Commissioner, LEIT',
            'department_id' => $department->id,
        ]);
        $secretary = User::factory()->role(Role::Secretary)->create([
            'full_name' => 'LEIT Department Secretary',
            'title' => 'Department Secretary',
            'department_id' => $department->id,
        ]);
        $departmentOfficer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Department Officer',
            'department_id' => $department->id,
        ]);
        $outsideOfficer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Outside Officer',
            'department_id' => $outsideDepartment->id,
        ]);
        $outsideSecretary = User::factory()->role(Role::Secretary)->create([
            'full_name' => 'Basic Education Department Secretary',
            'department_id' => $outsideDepartment->id,
        ]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $commissioner->id,
            'organizational_unit_id' => $unit->id,
            'official_job_title' => 'Department Secretary',
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => false,
            'delegated_permissions' => [],
            'active' => true,
        ]);

        $task = Task::factory()->create([
            'title' => 'Coordinate digital learning implementation',
            'assigned_by_user_id' => $ps->id,
            'creator_user_id' => $ps->id,
            'owner_user_id' => $ps->id,
            'assigned_to_user_id' => $commissioner->id,
            'current_assignee_user_id' => $commissioner->id,
            'responsible_user_id' => $commissioner->id,
            'department_id' => $department->id,
        ]);
        AssignmentWorkflowStep::create([
            'task_id' => $task->id,
            'sender_user_id' => $ps->id,
            'recipient_user_id' => $commissioner->id,
            'sequence' => 1,
            'status' => 'active',
            'instructions' => 'Coordinate implementation across the LEIT department.',
            'assigned_at' => now(),
            'due_at' => now()->addWeek(),
            'is_current' => true,
            'is_direct' => false,
        ]);

        return [$task, $secretary, $commissioner, $ps, $departmentOfficer, $outsideOfficer, $outsideSecretary];
    }
}
