<?php

namespace Tests\Feature\Admin;

use App\Enums\AssignmentLevel;
use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\AssignmentParticipant;
use App\Models\AssignmentWorkflowStep;
use App\Models\CorrespondenceRecipient;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\Notification;
use App\Models\OfficeScheduleItem;
use App\Models\OrganizationalUnit;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use App\Services\DepartmentAccessService;
use App\Services\OrganizationalScopeService;
use App\Services\SearchService;
use App\Services\SecretaryAuthorityService;
use App\Services\Tasks\AssignmentTargetService;
use App\Services\Tasks\TaskScope;
use App\Services\Tasks\TaskService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecretaryOfficeDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_assigns_gorreti_to_ps_office_without_granting_ps_authority(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $formerDepartment = Department::factory()->create();
        $ps = User::factory()->role(Role::Ps)->create([
            'full_name' => 'Kedrace Turyagyenda',
            'title' => 'Permanent Secretary',
        ]);
        $gorreti = User::factory()->role(Role::Clerk)->create([
            'full_name' => 'Gorreti Namukwaya',
            'employee_number' => '14208',
            'title' => 'PS Data Entry Clerk',
            'department_id' => $formerDepartment->id,
        ]);
        $office = OrganizationalUnit::where('code', 'OPS')->firstOrFail();
        $assignee = User::factory()->role(Role::Officer)->create();
        $psTask = Task::factory()->create([
            'assignment_level' => AssignmentLevel::Ps->value,
            'assigned_by_user_id' => $ps->id,
            'creator_user_id' => $ps->id,
            'owner_user_id' => $ps->id,
            'assigned_to_user_id' => $assignee->id,
            'current_assignee_user_id' => $assignee->id,
        ]);
        Task::factory()->create(['department_id' => $formerDepartment->id]);
        $normalMail = MailRecord::factory()->create([
            'direction' => 'incoming',
            'recipient_name' => 'Permanent Secretary',
            'confidentiality' => 'normal',
        ]);
        $restrictedMail = MailRecord::factory()->create([
            'direction' => 'incoming',
            'recipient_name' => 'Permanent Secretary',
            'confidentiality' => 'restricted',
        ]);

        $this->actingAs($admin)->post(route('admin.hierarchy.secretary-attachments.store'), [
            'secretary_user_id' => $gorreti->id,
            'supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $office->id,
            'official_job_title' => 'Senior Personal Secretary to the Permanent Secretary',
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'ends_at' => null,
            'delegated_actions_permitted' => false,
            'delegated_permissions' => [],
            'reason' => 'Approved PS office attachment.',
        ])->assertRedirect();

        $gorreti->refresh()->unsetRelation('roles');
        $this->assertSame(Role::Secretary, $gorreti->role);
        $this->assertSame('Senior Personal Secretary to the Permanent Secretary', $gorreti->title);
        $this->assertSame($ps->id, $gorreti->supervisor_user_id);
        $this->assertDatabaseHas('secretary_office_attachments', [
            'secretary_user_id' => $gorreti->id,
            'supervisor_user_id' => $ps->id,
            'active' => true,
            'delegated_actions_permitted' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'SecretaryOfficeAttachment',
            'actor_user_id' => $admin->id,
        ]);
        $this->assertFalse(app(TaskPolicy::class)->create($gorreti));
        $this->assertFalse(app(SecretaryAuthorityService::class)->allows($gorreti, 'assignments.approve'));
        $this->assertTrue(app(SecretaryAuthorityService::class)->allows($gorreti, 'mail.manage'));
        $this->assertTrue(app(SecretaryAuthorityService::class)->allows($gorreti, 'mail.assign'));
        $this->assertTrue($gorreti->can('view', $normalMail));
        $this->assertTrue($gorreti->can('view', $restrictedMail));

        $this->actingAs($gorreti)->get(route('secretary.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboards/secretary-office')
                ->where('identity.full_name', 'Gorreti Namukwaya')
                ->where('identity.official_job_title', 'Senior Personal Secretary to the Permanent Secretary')
                ->where('identity.office_name', 'Office of the Permanent Secretary')
                ->where('identity.delegated_permissions', [])
                ->where('stats.total', 1)
                ->where('stats.incoming', 2)
                ->where('can_manage_mail', true)
                ->has('follow_ups', 1)
                ->where('follow_ups.0.id', $psTask->id));
        $this->get(route('exec.dashboard'))->assertForbidden();
    }

    public function test_supported_office_allows_multiple_secretaries_and_preserves_shared_records(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $ps = User::factory()->role(Role::Ps)->create(['title' => 'Permanent Secretary']);
        $gorreti = User::factory()->role(Role::Secretary)->create([
            'full_name' => 'Gorreti Namukwaya',
            'title' => 'Senior Personal Secretary to the Permanent Secretary',
        ]);
        $replacement = User::factory()->role(Role::Secretary)->create(['title' => 'Senior Personal Secretary']);
        $task = Task::factory()->level(AssignmentLevel::Ps)->create([
            'assigned_by_user_id' => $ps->id,
            'creator_user_id' => $ps->id,
            'owner_user_id' => $ps->id,
            'assigned_to_user_id' => $gorreti->id,
            'current_assignee_user_id' => $gorreti->id,
        ]);

        $payload = [
            'supervisor_user_id' => $ps->id,
            'official_job_title' => 'Senior Personal Secretary to the Permanent Secretary',
            'starts_at' => now()->subMinutes(2)->format('Y-m-d H:i:s'),
            'delegated_actions_permitted' => false,
            'delegated_permissions' => [],
            'reason' => 'Initial attachment.',
        ];
        $this->actingAs($admin)->post(route('admin.hierarchy.secretary-attachments.store'), [
            ...$payload,
            'secretary_user_id' => $gorreti->id,
        ])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.hierarchy.secretary-attachments.store'), [
            ...$payload,
            'secretary_user_id' => $replacement->id,
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'reason' => 'Approved shared office coverage.',
        ])->assertRedirect();

        $this->assertTrue($gorreti->currentSecretaryAttachment()->exists());
        $this->assertTrue($replacement->currentSecretaryAttachment()->exists());
        $this->actingAs($gorreti)->get(route('secretary.dashboard'))->assertOk();
        $this->actingAs($replacement)->get(route('secretary.dashboard'))->assertOk();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'assigned_to_user_id' => $gorreti->id,
            'current_assignee_user_id' => $gorreti->id,
        ]);
        $this->assertDatabaseCount('secretary_office_attachments', 2);

        $gorretiAttachment = $gorreti->currentSecretaryAttachment()->firstOrFail();
        $this->actingAs($admin)->delete(route('admin.hierarchy.secretary-attachments.destroy', $gorretiAttachment), [
            'reason' => 'Secretary transferred out of the shared office.',
        ])->assertRedirect();

        $this->assertFalse($gorreti->currentSecretaryAttachment()->exists());
        $this->assertTrue($replacement->currentSecretaryAttachment()->exists());
        $this->actingAs($gorreti)->get(route('secretary.dashboard'))->assertForbidden();
        $this->actingAs($replacement)->get(route('secretary.dashboard'))->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_ending_department_secretary_attachment_revokes_copied_profile_authority(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $department = Department::factory()->create(['name' => 'Revoked Secretary Department']);
        $office = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'Revoked Secretary Department Office',
            'code' => 'REVOKED-SECRETARY',
            'active' => true,
        ]);
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $secretary = User::factory()->role(Role::Officer)->create();
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $mail = MailRecord::factory()->incoming()->create([
            'subject' => 'Former office confidential marker',
            'department_id' => $department->id,
            'organizational_unit_id' => $office->id,
            'office_supervisor_user_id' => $commissioner->id,
        ]);
        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $officer->id,
            'current_assignee_user_id' => $officer->id,
            'responsible_user_id' => $officer->id,
            'department_id' => $department->id,
            'owner_organizational_unit_id' => $office->id,
        ]);

        $this->actingAs($admin)->post(route('admin.hierarchy.secretary-attachments.store'), [
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $commissioner->id,
            'organizational_unit_id' => $office->id,
            'official_job_title' => 'Department Secretary',
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'delegated_actions_permitted' => true,
            'delegated_permissions' => ['mail.manage', 'mail.assign'],
            'reason' => 'Temporary department support.',
        ])->assertSessionHasNoErrors();

        $secretary->refresh()->unsetRelation('roles');
        $this->assertSame($office->id, $secretary->organizational_unit_id);
        $this->assertSame($department->id, $secretary->department_id);
        $this->assertTrue($secretary->can('view', $mail));
        $this->assertTrue(app(TaskScope::class)->allows($secretary, $task));
        $this->assertTrue(app(SecretaryAuthorityService::class)->allows($secretary, 'mail.manage'));
        $this->actingAs($secretary)->get(route('mail.show', $mail))->assertOk();
        $this->actingAs($secretary)->get(route('tasks.show', $task))->assertOk();

        $secretary->load('currentSecretaryAttachment.supervisor');
        $attachment = $secretary->currentSecretaryAttachment()->firstOrFail();
        $this->actingAs($admin)->delete(route('admin.hierarchy.secretary-attachments.destroy', $attachment), [
            'reason' => 'Department support appointment ended.',
        ])->assertSessionHasNoErrors();

        // A relation loaded before revocation must not restore OPS or
        // department access in a long-lived process.
        $this->assertNotNull($secretary->currentSecretaryAttachment);
        $this->assertFalse($secretary->can('view', $mail));

        $secretary->refresh()->unsetRelation('roles');
        $this->assertFalse($secretary->currentSecretaryAttachment()->exists());
        $this->assertFalse($secretary->can('view', $mail));
        $this->assertFalse(app(TaskScope::class)->allows($secretary, $task));
        $this->assertFalse(app(SecretaryAuthorityService::class)->allows($secretary, 'mail.manage'));
        $this->actingAs($secretary)->get(route('mail.show', $mail))->assertForbidden();
        $this->actingAs($secretary)->get(route('tasks.show', $task))->assertForbidden();

        $this->assertFalse(app(AssignmentTargetService::class)
            ->departmentMembers($department->id)
            ->contains($secretary));
        $newTask = app(TaskService::class)->create($commissioner, [
            'target_type' => 'department',
            'target_department_id' => $department->id,
            'title' => 'Post-revocation department assignment',
            'description' => 'Former secretaries must not receive new office work.',
            'priority' => Priority::Medium->value,
            'due_date' => null,
            'instructions' => null,
        ]);
        $this->assertFalse(AssignmentParticipant::query()
            ->where('task_id', $newTask->id)
            ->where('user_id', $secretary->id)
            ->exists());
        $this->assertFalse(AssignmentWorkflowStep::query()
            ->where('task_id', $newTask->id)
            ->where('recipient_user_id', $secretary->id)
            ->exists());
        $this->assertFalse(Notification::query()
            ->where('related_task_id', $newTask->id)
            ->where('user_id', $secretary->id)
            ->exists());
    }

    public function test_expired_secretary_attachment_does_not_reactivate_profile_fallback(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $department = Department::factory()->create(['name' => 'Expired Secretary Department']);
        $office = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'Expired Secretary Department Office',
            'code' => 'EXPIRED-SECRETARY',
            'active' => true,
        ]);
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $secretary = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'department_id' => $department->id,
            'organizational_unit_id' => $office->id,
            'office_supervisor_user_id' => $commissioner->id,
        ]);

        $this->actingAs($admin)->post(route('admin.hierarchy.secretary-attachments.store'), [
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $commissioner->id,
            'organizational_unit_id' => $office->id,
            'official_job_title' => 'Department Secretary',
            'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'delegated_actions_permitted' => true,
            'delegated_permissions' => ['mail.manage'],
            'reason' => 'Historical fixed-term department support.',
        ])->assertSessionHasNoErrors();

        $secretary->refresh()->unsetRelation('roles');
        $this->assertFalse($secretary->currentSecretaryAttachment()->exists());
        $this->assertSame($office->id, $secretary->organizational_unit_id);
        $this->assertSame($department->id, $secretary->department_id);
        $this->assertFalse($secretary->can('view', $mail));
        $this->assertFalse(app(SecretaryAuthorityService::class)->allows($secretary, 'mail.manage'));
        $this->actingAs($secretary)->get(route('mail.show', $mail))->assertForbidden();
    }

    public function test_natural_expiry_changes_search_cache_scope_and_hides_department_directory(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');

        try {
            $admin = User::factory()->role(Role::Sysadmin)->create();
            $department = Department::factory()->create(['name' => 'Expiry Cache Department']);
            $office = OrganizationalUnit::create([
                'department_id' => $department->id,
                'type' => 'department',
                'name' => 'Expiry Cache Department Office',
                'code' => 'EXPIRY-CACHE',
                'active' => true,
            ]);
            $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
            $secretary = User::factory()->role(Role::Officer)->create();
            User::factory()->role(Role::Officer)->create([
                'full_name' => 'Natural Expiry Directory Marker',
                'department_id' => $department->id,
            ]);

            $this->actingAs($admin)->post(route('admin.hierarchy.secretary-attachments.store'), [
                'secretary_user_id' => $secretary->id,
                'supervisor_user_id' => $commissioner->id,
                'organizational_unit_id' => $office->id,
                'official_job_title' => 'Fixed Term Secretary',
                'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addMinutes(5)->format('Y-m-d H:i:s'),
                'delegated_actions_permitted' => false,
                'reason' => 'Cache boundary regression.',
            ])->assertSessionHasNoErrors();

            $secretary->refresh()->unsetRelation('roles');
            $search = app(SearchService::class);
            $this->assertSame(1, $search->search($secretary, 'Natural Expiry Directory Marker', 'staff')['total']);

            Carbon::setTestNow('2026-09-03 10:06:00');
            $this->assertSame(0, $search->search($secretary, 'Natural Expiry Directory Marker', 'staff')['total']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_profile_units_remain_valid_for_non_secretaries_and_direct_secretary_links_are_role_gated(): void
    {
        $department = Department::factory()->create();
        $office = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'unit',
            'name' => 'Profile Placement Unit',
            'code' => 'PROFILE-UNIT',
            'active' => true,
        ]);
        $officer = User::factory()->role(Role::Officer)->create([
            'department_id' => null,
            'organizational_unit_id' => $office->id,
        ]);

        $this->assertSame($office->id, app(OrganizationalScopeService::class)->primaryUnit($officer)?->id);

        $formerSecretary = User::factory()->role(Role::Officer)->create([
            'department_id' => null,
            'organizational_unit_id' => null,
        ]);
        $office->update(['secretary_user_id' => $formerSecretary->id]);

        $this->assertSame([], app(DepartmentAccessService::class)->currentDepartmentIds($formerSecretary));
        $targets = app(AssignmentTargetService::class);
        $this->assertFalse($targets->officeMembers($office->id)->contains($formerSecretary));
        $this->assertFalse($targets->departmentMembers($department->id)->contains($formerSecretary));

        $commissioner = User::factory()->role(Role::Commissioner)->create([
            'department_id' => $department->id,
        ]);
        $task = app(TaskService::class)->create($commissioner, [
            'target_type' => 'department',
            'target_department_id' => $department->id,
            'title' => 'Role-gated direct secretary assignment',
            'description' => null,
            'priority' => Priority::Medium->value,
            'due_date' => null,
            'instructions' => null,
        ]);
        $this->assertFalse(AssignmentParticipant::query()
            ->where('task_id', $task->id)
            ->where('user_id', $formerSecretary->id)
            ->exists());
        $this->assertFalse(AssignmentWorkflowStep::query()
            ->where('task_id', $task->id)
            ->where('recipient_user_id', $formerSecretary->id)
            ->exists());
    }

    public function test_explicit_delegation_enables_only_selected_actions_and_schedule_management(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['title' => 'Commissioner, Human Resource Management']);
        $secretary = User::factory()->role(Role::Secretary)->create(['title' => 'Senior Personal Secretary']);

        $this->actingAs($admin)->post(route('admin.hierarchy.secretary-attachments.store'), [
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $commissioner->id,
            'official_job_title' => 'Senior Personal Secretary',
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'delegated_actions_permitted' => true,
            'delegated_permissions' => ['assignments.create', 'mail.manage'],
            'reason' => 'Delegated operational support.',
        ])->assertRedirect();

        $this->assertTrue(app(SecretaryAuthorityService::class)->allows($secretary, 'assignments.create'));
        $this->assertTrue(app(SecretaryAuthorityService::class)->allows($secretary, 'mail.manage'));
        $this->assertFalse(app(SecretaryAuthorityService::class)->allows($secretary, 'assignments.approve'));
        $this->assertTrue(app(TaskPolicy::class)->create($secretary));

        $this->actingAs($secretary)->post(route('secretary.schedule.store'), [
            'type' => 'meeting',
            'title' => 'Commissioner weekly brief',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $this->assertDatabaseHas('office_schedule_items', ['title' => 'Commissioner weekly brief']);
    }

    public function test_department_secretary_dashboard_builds_live_assignment_reminders_and_queues(): void
    {
        $department = Department::factory()->create();
        $ps = User::factory()->role(Role::Ps)->create(['title' => 'Permanent Secretary']);
        $commissioner = User::factory()->role(Role::Commissioner)->create([
            'department_id' => $department->id,
            'title' => 'Commissioner, Human Resource Management',
        ]);
        $secretary = User::factory()->role(Role::Secretary)->create(['department_id' => $department->id]);
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);

        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $commissioner->id,
            'official_job_title' => 'Senior Personal Secretary',
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => false,
            'delegated_permissions' => [],
            'active' => true,
        ]);

        $commissionerQueue = Task::factory()->level(AssignmentLevel::Ps)->create([
            'title' => 'Prepare response for the PS Office',
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $commissioner->id,
            'current_assignee_user_id' => $commissioner->id,
            'responsible_user_id' => $commissioner->id,
            'department_id' => $department->id,
            'due_date' => now()->addDay()->toDateString(),
        ]);
        $notStarted = Task::factory()->create([
            'title' => 'Compile department staffing return',
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $officer->id,
            'current_assignee_user_id' => $officer->id,
            'responsible_user_id' => $officer->id,
            'department_id' => $department->id,
            'due_date' => now()->addDays(2)->toDateString(),
            'first_viewed_at' => null,
            'progress_percent' => 0,
        ]);
        $outstanding = Task::factory()->status(TaskStatus::InProgress)->create([
            'title' => 'Complete the establishment review',
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $officer->id,
            'current_assignee_user_id' => $officer->id,
            'responsible_user_id' => $officer->id,
            'department_id' => $department->id,
            'due_date' => now()->addDays(3)->toDateString(),
            'first_viewed_at' => now(),
            'progress_percent' => 25,
        ]);

        $this->actingAs($secretary)->get(route('secretary.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignment_queue.0.id', $commissionerQueue->id)
                ->has('assignment_queue', 1)
                ->has('follow_ups', 2)
                ->where('follow_ups.0.id', $notStarted->id)
                ->where('follow_ups.1.id', $outstanding->id)
                ->has('office_notifications', 3)
                ->where('office_notifications.0.kind', 'supervisor')
                ->where('office_notifications.0.task_id', $commissionerQueue->id)
                ->where('office_notifications.1.kind', 'unhandled')
                ->where('office_notifications.1.task_id', $notStarted->id)
                ->where('office_notifications.2.kind', 'outstanding')
                ->where('office_notifications.2.task_id', $outstanding->id)
                ->where('section_counts.assignment_queue', 1)
                ->where('section_counts.follow_ups', 2)
                ->where('section_counts.notifications', 3)
                ->where('assignment_queue.0.assigned_by_name', $ps->full_name)
                ->where('assignment_queue.0.current_assignee_name', $commissioner->full_name)
                ->where('assignment_queue.0.department_name', $department->name));
    }

    public function test_department_profile_secretaries_use_the_uniform_scoped_dashboard_and_shared_calendar(): void
    {
        $department = Department::factory()->create(['name' => 'Teacher Education', 'code' => 'TE']);
        $outsideDepartment = Department::factory()->create(['name' => 'Basic Education', 'code' => 'BE']);
        $commissioner = User::factory()->role(Role::Commissioner)->create([
            'full_name' => 'Commissioner Teacher Education',
            'department_id' => $department->id,
        ]);
        $department->update(['head_user_id' => $commissioner->id]);
        $secretary = User::factory()->role(Role::Secretary)->create([
            'full_name' => 'Teacher Education Secretary',
            'title' => 'Department Secretary',
            'department_id' => $department->id,
        ]);
        $colleague = User::factory()->role(Role::Secretary)->create(['department_id' => $department->id]);
        $outsideSecretary = User::factory()->role(Role::Secretary)->create(['department_id' => $outsideDepartment->id]);

        $visibleTask = Task::factory()->create([
            'title' => 'Prepare teacher education brief',
            'assigned_to_user_id' => $commissioner->id,
            'current_assignee_user_id' => $commissioner->id,
            'department_id' => $department->id,
        ]);
        Task::factory()->create(['department_id' => $outsideDepartment->id]);
        $visibleMail = MailRecord::factory()->incoming()->create(['department_id' => $department->id]);
        MailRecord::factory()->incoming()->create(['department_id' => $outsideDepartment->id]);

        $this->actingAs($secretary)->get(route('secretary.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboards/secretary-office')
                ->where('identity.office_name', 'Teacher Education')
                ->where('identity.supervisor_name', 'Commissioner Teacher Education')
                ->where('stats.total', 1)
                ->where('section_counts.correspondence', 1)
                ->where('assignment_queue.0.id', $visibleTask->id)
                ->where('correspondence.0.id', $visibleMail->id));
        $this->actingAs($secretary)->get(route('dept.dashboard'))->assertRedirect(route('secretary.dashboard'));

        $this->actingAs($secretary)->post(route('secretary.schedule.store'), [
            'type' => 'meeting',
            'title' => 'Department planning meeting',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $scheduleItem = OfficeScheduleItem::where('title', 'Department planning meeting')->firstOrFail();
        $this->assertNull($scheduleItem->secretary_office_attachment_id);
        $this->assertSame($department->id, $scheduleItem->department_id);
        $this->actingAs($colleague)->get(route('secretary.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('section_counts.schedule', 1)
                ->where('schedule.0.id', $scheduleItem->id));
        $this->actingAs($outsideSecretary)->get(route('secretary.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 1)
                ->where('section_counts.correspondence', 1)
                ->where('section_counts.schedule', 0)
                ->has('schedule', 0));
        $this->actingAs($outsideSecretary)->delete(route('secretary.schedule.destroy', $scheduleItem))->assertForbidden();
    }

    public function test_ps_office_secretary_creates_reviews_and_dispatches_shared_correspondence_on_behalf_of_ps(): void
    {
        $ps = User::factory()->role(Role::Ps)->create([
            'full_name' => 'Kedrace Turyagyenda',
            'title' => 'Permanent Secretary',
        ]);
        $secretary = User::factory()->role(Role::Secretary)->create([
            'full_name' => 'Gorreti Namukwaya',
            'title' => 'Senior Personal Secretary to the Permanent Secretary',
        ]);
        $office = OrganizationalUnit::where('code', 'OPS')->firstOrFail();
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $office->id,
            'official_job_title' => $secretary->title,
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => false,
            'delegated_permissions' => [],
            'active' => true,
        ]);

        $this->actingAs($secretary)->post(route('mail.outgoing.store'), [
            'sender_name' => 'Office of the Permanent Secretary',
            'sender_organisation' => 'Ministry of Education and Sports',
            'recipient_name' => 'Office of the Auditor General',
            'subject' => 'Response to audit observations',
            'correspondence_reference' => 'PS/ADM/2026/041',
            'letter_date' => now()->toDateString(),
            'sent_date' => null,
            'confidentiality' => 'restricted',
            'priority' => 'urgent',
            'status' => 'draft',
        ])->assertRedirect();

        $mail = MailRecord::where('subject', 'Response to audit observations')->firstOrFail();
        $this->assertSame($ps->id, $mail->office_supervisor_user_id);
        $this->assertSame($office->id, $mail->organizational_unit_id);
        $this->assertSame($ps->id, $mail->prepared_on_behalf_of_user_id);
        $this->assertSame($secretary->id, $mail->captured_by_user_id);
        $this->assertSame('draft', $mail->status->value);
        $this->actingAs($ps)->get(route('mail.show', $mail))->assertOk();

        $this->actingAs($secretary)->post(route('mail.transition', $mail), [
            'status' => 'awaiting_review',
            'note' => 'Prepared for review and signature.',
        ])->assertRedirect();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $ps->id,
            'type' => 'correspondence_review',
        ]);

        $this->actingAs($secretary)->post(route('mail.transition', $mail), [
            'status' => 'approved',
        ])->assertSessionHasErrors('status');

        $this->actingAs($ps)->post(route('mail.transition', $mail), [
            'status' => 'approved',
            'note' => 'Approved for signature and dispatch.',
        ])->assertRedirect();
        $this->assertSame('approved', $mail->refresh()->status->value);

        $this->actingAs($secretary)->post(route('mail.transition', $mail), [
            'status' => 'dispatched',
            'dispatch_method' => 'Courier',
            'dispatch_reference' => 'COURIER-2041',
            'dispatched_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $mail->refresh();
        $this->assertSame('dispatched', $mail->status->value);
        $this->assertSame('COURIER-2041', $mail->dispatch_reference);
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'MailRecord',
            'target_id' => $mail->id,
            'actor_user_id' => $secretary->id,
        ]);
    }

    public function test_ps_office_secretary_can_manage_recipients_on_forwarded_correspondence(): void
    {
        $ps = User::factory()->role(Role::Ps)->create(['title' => 'Permanent Secretary']);
        $secretary = User::factory()->role(Role::Secretary)->create([
            'title' => 'Senior Personal Secretary to the Permanent Secretary',
        ]);
        $firstRecipient = User::factory()->role(Role::Officer)->create();
        $replacementRecipient = User::factory()->role(Role::Officer)->create();
        $office = OrganizationalUnit::where('code', 'OPS')->firstOrFail();
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $office->id,
            'official_job_title' => $secretary->title,
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => false,
            'delegated_permissions' => [],
            'active' => true,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretary->id,
            'office_supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $office->id,
        ]);

        $this->actingAs($secretary)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $firstRecipient->id,
            'action_required' => false,
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $this->actingAs($secretary)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.can_assign', true)
                ->where('selectedMail.can_edit', true));

        $this->actingAs($secretary)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $replacementRecipient->id,
            'action_required' => false,
            'priority' => 'medium',
            'instructions' => 'Recipient list corrected by the PS Secretary.',
        ])->assertSessionHasNoErrors();

        $firstRecipientLink = CorrespondenceRecipient::query()
            ->where('correspondence_id', $mail->refresh()->correspondence_id)
            ->where('user_id', $firstRecipient->id)
            ->where('active', true)
            ->firstOrFail();

        $this->actingAs($secretary)->delete(route('mail.recipients.destroy', [$mail, $firstRecipientLink]), [
            'reason' => 'Replaced with the correct action office.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('correspondence_recipients', [
            'id' => $firstRecipientLink->id,
            'active' => false,
            'removed_by_user_id' => $secretary->id,
        ]);
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_id' => $mail->correspondence_id,
            'user_id' => $replacementRecipient->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'recipient_removed',
            'performed_by_user_id' => $secretary->id,
        ]);
    }
}
