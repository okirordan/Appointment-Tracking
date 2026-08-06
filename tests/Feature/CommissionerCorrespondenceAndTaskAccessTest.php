<?php

namespace Tests\Feature;

use App\Enums\Role as SystemRole;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPosition;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CommissionerCorrespondenceAndTaskAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_commissioner_views_and_assigns_correspondence_only_within_their_current_department(): void
    {
        $department = Department::factory()->create(['name' => 'Library, E-learning and Information Technology', 'code' => 'LEIT']);
        $otherDepartment = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN']);
        $commissioner = User::factory()->role(SystemRole::Commissioner)->create(['department_id' => $department->id]);
        $firstOfficer = User::factory()->role(SystemRole::Officer)->create([
            'full_name' => 'First LEIT Officer',
            'department_id' => $department->id,
        ]);
        $secondOfficer = User::factory()->role(SystemRole::Officer)->create([
            'full_name' => 'Second LEIT Officer',
            'department_id' => $department->id,
        ]);
        $outsideOfficer = User::factory()->role(SystemRole::Officer)->create(['department_id' => $otherDepartment->id]);

        $incoming = MailRecord::factory()->incoming()->create([
            'department_id' => $department->id,
            'subject' => 'Digital library connectivity plan',
            'sender_name' => 'National Library',
        ]);
        $outgoing = MailRecord::factory()->outgoing()->create([
            'department_id' => $department->id,
            'subject' => 'E-learning platform response',
        ]);
        $outside = MailRecord::factory()->incoming()->create([
            'department_id' => $otherDepartment->id,
            'subject' => 'Confidential finance correspondence',
            'confidentiality' => 'restricted',
        ]);

        $this->actingAs($commissioner)->get(route('mail.incoming.index', ['q' => 'connectivity']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('registerOfficeName', $department->name)
                ->where('mails.meta.total', 1)
                ->where('mails.data.0.id', $incoming->id)
                ->where('departmentOptions.0.id', $department->id)
                ->where('canManageRegister', false));
        $this->actingAs($commissioner)->get(route('mail.outgoing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.meta.total', 1)
                ->where('mails.data.0.id', $outgoing->id));
        $this->actingAs($commissioner)->get(route('mail.show', $incoming))->assertOk();
        $this->actingAs($commissioner)->get(route('mail.show', $outside))->assertForbidden();

        $this->actingAs($commissioner)->post(route('mail.assign', $incoming), [
            'department_id' => $department->id,
            'assigned_to_user_ids' => [$firstOfficer->id, $secondOfficer->id],
            'priority' => 'high',
            'due_date' => today()->addWeek()->toDateString(),
            'instructions' => 'Prepare the departmental response.',
        ])->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        $this->assertSame($task->id, $incoming->refresh()->task_id);
        $this->assertCount(2, $task->workflowSteps()->where('is_current', true)->get());
        $this->assertDatabaseHas('assignment_participants', [
            'task_id' => $task->id,
            'user_id' => $secondOfficer->id,
            'participant_type' => 'assignee',
            'active' => true,
        ]);

        $otherMail = MailRecord::factory()->incoming()->create(['department_id' => $department->id]);
        $this->actingAs($commissioner)->post(route('mail.assign', $otherMail), [
            'department_id' => $otherDepartment->id,
            'assigned_to_user_ids' => [$outsideOfficer->id],
            'priority' => 'medium',
        ])->assertSessionHasErrors('assigned_to_user_ids');
        $this->assertNull($otherMail->refresh()->task_id);
    }

    public function test_effective_dated_replacement_gains_historical_department_mail_and_former_holder_loses_it(): void
    {
        $department = Department::factory()->create(['code' => 'LEIT']);
        $unit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => $department->name,
            'code' => 'LEIT-OFFICE',
            'active' => true,
        ]);
        $commissionerRole = Role::where('name', SystemRole::Commissioner->value)->firstOrFail();
        $position = Position::create([
            'organizational_unit_id' => $unit->id,
            'role_id' => $commissionerRole->id,
            'title' => 'Commissioner, LEIT',
            'hierarchy_level' => 20,
            'workflow_capabilities' => ['assign', 'review', 'approve'],
            'active' => true,
        ]);
        $former = User::factory()->role(SystemRole::Commissioner)->create(['department_id' => $department->id]);
        $replacement = User::factory()->role(SystemRole::Commissioner)->create(['department_id' => $department->id]);
        UserPosition::create([
            'user_id' => $former->id,
            'position_id' => $position->id,
            'is_primary' => true,
            'starts_at' => now()->subYear(),
            'ends_at' => now()->subMinute(),
            'active' => true,
        ]);
        UserPosition::create([
            'user_id' => $replacement->id,
            'position_id' => $position->id,
            'is_primary' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'active' => true,
        ]);
        $historicalMail = MailRecord::factory()->incoming()->create([
            'department_id' => $department->id,
            'organizational_unit_id' => $unit->id,
            'subject' => 'Historical departmental correspondence',
        ]);

        $this->actingAs($former)->get(route('mail.show', $historicalMail))->assertForbidden();
        $this->actingAs($replacement)->get(route('mail.show', $historicalMail))->assertOk();
    }

    public function test_tasks_follow_participants_and_reporting_chain_not_department_office_holders(): void
    {
        Storage::fake('evidence');
        $department = Department::factory()->create(['code' => 'LEIT']);
        $firstCommissioner = User::factory()->role(SystemRole::Commissioner)->create(['department_id' => $department->id]);
        $replacementCommissioner = User::factory()->role(SystemRole::Commissioner)->create(['department_id' => $department->id]);
        $firstOfficer = User::factory()->role(SystemRole::Officer)->create([
            'department_id' => $department->id,
            'supervisor_user_id' => $firstCommissioner->id,
        ]);
        $secondOfficer = User::factory()->role(SystemRole::Officer)->create([
            'department_id' => $department->id,
            'supervisor_user_id' => $firstCommissioner->id,
        ]);

        $this->actingAs($firstCommissioner)->post(route('tasks.store'), [
            'title' => 'Department digitisation work',
            'description' => 'Complete the digitisation work package.',
            'assigned_to_user_ids' => [$firstOfficer->id, $secondOfficer->id],
            'priority' => 'high',
            'due_date' => today()->addDays(10)->toDateString(),
            'instructions' => 'Coordinate and submit one departmental output.',
            'attachments' => [UploadedFile::fake()->create('terms-of-reference.pdf', 200, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        $this->assertCount(2, $task->workflowSteps()->where('is_current', true)->get());
        $this->assertSame($firstCommissioner->roleLabel(), $task->assigned_by_role_snapshot);
        $this->assertSame($department->id, $task->assigned_by_department_id);
        $this->assertDatabaseHas('evidence_attachments', [
            'task_id' => $task->id,
            'original_filename' => 'terms-of-reference.pdf',
            'uploaded_by_user_id' => $firstCommissioner->id,
        ]);
        $this->actingAs($firstCommissioner)->get(route('tasks.show', $task))->assertOk();
        $this->actingAs($replacementCommissioner)->get(route('tasks.show', $task))->assertForbidden();

        $firstOfficer->update(['supervisor_user_id' => $replacementCommissioner->id]);
        $this->actingAs($replacementCommissioner)->get(route('tasks.show', $task))->assertOk();
        $this->actingAs($firstCommissioner)->get(route('tasks.show', $task))->assertOk();
    }
}
