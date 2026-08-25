<?php

namespace Tests\Feature\Admin;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\CorrespondenceRecipient;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use App\Services\SecretaryAuthorityService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $ps = User::factory()->role(Role::Ps)->create([
            'full_name' => 'Kedrace Turyagyenda',
            'title' => 'Permanent Secretary',
        ]);
        $gorreti = User::factory()->role(Role::Clerk)->create([
            'full_name' => 'Gorreti Namukwaya',
            'employee_number' => '14208',
            'title' => 'PS Data Entry Clerk',
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
                ->where('office_notifications.2.task_id', $outstanding->id));
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
