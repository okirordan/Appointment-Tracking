<?php

namespace Tests\Feature;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Correspondence;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CorrespondenceFilingAndOfficeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function psOffice(): OrganizationalUnit
    {
        return OrganizationalUnit::firstOrCreate(
            ['name' => 'Office of the Permanent Secretary'],
            ['type' => 'office', 'code' => 'PS-OFFICE', 'active' => true],
        );
    }

    public function test_forwarding_confirms_the_recipient_and_rejects_a_duplicate_second_submission(): void
    {
        $department = Department::factory()->create(['code' => 'BE']);
        $ps = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Grace Achieng',
            'department_id' => $department->id,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
        ]);

        $payload = [
            'action_required' => true,
            'target_type' => 'individual',
            'assigned_to_user_ids' => [$officer->id],
            'priority' => 'high',
            'instructions' => 'Prepare a response for signature.',
        ];

        $this->actingAs($ps)->post(route('mail.assign', $mail), $payload)
            ->assertRedirect(route('mail.show', $mail))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'forwarded successfully')
                && str_contains($message, 'Grace Achieng')
                && str_contains($message, $mail->register_number));

        $this->assertSame(
            CorrespondenceLifecycleStatus::ActionRequired,
            $mail->refresh()->correspondence->current_status,
        );

        // A repeated submission of the same forwarding action is refused
        // rather than creating a duplicate assignment for the same officer.
        $this->actingAs($ps)->post(route('mail.assign', $mail), $payload)
            ->assertSessionHasErrors('assigned_to_user_ids');
        $this->assertSame(1, Task::query()->count());
        $this->assertSame(1, $mail->correspondence->recipients()->where('active', true)->count());
    }

    public function test_incoming_correspondence_is_filed_without_creating_a_task_and_leaves_the_active_queue(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
        ]);

        $this->actingAs($ps)->post(route('mail.file', $mail), [
            'filing_category' => 'Circulars',
            'note' => 'Noted for the record; no action required.',
        ])
            ->assertRedirect(route('mail.show', $mail))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'filed'));

        $correspondence = $mail->refresh()->correspondence;
        $this->assertSame(CorrespondenceLifecycleStatus::Filed, $correspondence->current_status);
        $this->assertSame('Circulars', $correspondence->filing_category);
        $this->assertSame($ps->id, $correspondence->filed_by_user_id);
        $this->assertSame($this->psOffice()->id, $correspondence->filed_organizational_unit_id);
        $this->assertNotNull($correspondence->filed_at);
        $this->assertSame(0, Task::query()->count());
        $this->assertNull($mail->refresh()->task_id);

        // Filing, like every status change, is written to the audit trail and
        // to the immutable correspondence history.
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'MailRecord',
            'target_id' => $mail->id,
            'action' => "Filed correspondence {$mail->register_number}",
        ]);
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $correspondence->id,
            'type' => 'filed',
            'status_to' => 'filed',
            'performed_by_user_id' => $ps->id,
        ]);

        // It disappears from Active Incoming and appears under Filed.
        $this->actingAs($ps)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('mails.data', 0));
        $this->actingAs($ps)->get(route('mail.filed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('mail/index')
                ->where('direction', 'filed')
                ->has('mails.data', 1)
                ->where('mails.data.0.id', $mail->id)
                ->where('mails.data.0.filing_category', 'Circulars')
                ->where('stats.filed_total', 1));
    }

    public function test_filed_correspondence_can_be_searched_filtered_reopened_and_then_forwarded(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create(['full_name' => 'Peter Okello']);
        $filed = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
            'subject' => 'School inspection circular',
            'sender_name' => 'Directorate of Education Standards',
        ]);
        $other = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
            'subject' => 'Budget framework paper',
        ]);

        $this->actingAs($ps)->post(route('mail.file', $filed), ['filing_category' => 'Circulars']);
        $this->actingAs($ps)->post(route('mail.file', $other), ['filing_category' => 'Finance']);

        $this->actingAs($ps)->get(route('mail.filed.index', ['q' => 'inspection circular']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('mails.data', 1)
                ->where('mails.data.0.id', $filed->id));

        $this->actingAs($ps)->get(route('mail.filed.index', ['category' => 'Finance']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('mails.data', 1)
                ->where('mails.data.0.id', $other->id));

        // The complete record stays open for review, and offers reopening.
        $this->actingAs($ps)->get(route('mail.show', $filed))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('direction', 'filed')
                ->where('selectedMail.can_reopen', true)
                ->where('selectedMail.filing.category', 'Circulars'));

        $this->actingAs($ps)->post(route('mail.reopen', $filed), ['note' => 'Action is needed after all.'])
            ->assertRedirect(route('mail.show', $filed))
            ->assertSessionHasNoErrors();
        $this->assertSame(CorrespondenceLifecycleStatus::Incoming, $filed->refresh()->correspondence->current_status);
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'MailRecord',
            'target_id' => $filed->id,
            'action' => "Reopened filed correspondence {$filed->register_number}",
        ]);

        // Once reopened it behaves like any other active incoming item.
        $this->actingAs($ps)->post(route('mail.assign', $filed), [
            'action_required' => true,
            'target_type' => 'individual',
            'assigned_to_user_ids' => [$officer->id],
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();
        $this->assertSame(CorrespondenceLifecycleStatus::ActionRequired, $filed->refresh()->correspondence->current_status);
    }

    public function test_filed_correspondence_can_be_forwarded_directly_from_the_filed_register(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
        ]);
        $this->actingAs($ps)->post(route('mail.file', $mail));

        $this->actingAs($ps)->post(route('mail.assign', $mail), [
            'action_required' => false,
            'target_type' => 'individual',
            'assigned_to_user_ids' => [$officer->id],
            'priority' => 'low',
        ])->assertSessionHasNoErrors();

        $this->assertSame(CorrespondenceLifecycleStatus::Forwarded, $mail->refresh()->correspondence->current_status);
    }

    public function test_filed_correspondence_is_only_visible_to_offices_the_user_is_authorised_for(): void
    {
        $ownDepartment = Department::factory()->create(['code' => 'BE']);
        $otherDepartment = Department::factory()->create(['code' => 'HE']);
        $ownUnit = OrganizationalUnit::create([
            'department_id' => $ownDepartment->id,
            'type' => 'department',
            'name' => 'Basic Education Department',
            'code' => 'BE-OFFICE',
            'active' => true,
        ]);
        $otherUnit = OrganizationalUnit::create([
            'department_id' => $otherDepartment->id,
            'type' => 'department',
            'name' => 'Higher Education Department',
            'code' => 'HE-OFFICE',
            'active' => true,
        ]);
        $ownCommissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $ownDepartment->id]);
        $otherCommissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $otherDepartment->id]);

        $ownMail = MailRecord::factory()->incoming()->create([
            'department_id' => $ownDepartment->id,
            'organizational_unit_id' => $ownUnit->id,
            'captured_by_user_id' => $ownCommissioner->id,
        ]);
        $otherMail = MailRecord::factory()->incoming()->create([
            'department_id' => $otherDepartment->id,
            'organizational_unit_id' => $otherUnit->id,
            'captured_by_user_id' => $otherCommissioner->id,
        ]);
        $this->actingAs($ownCommissioner)->post(route('mail.file', $ownMail))->assertSessionHasNoErrors();
        $this->actingAs($otherCommissioner)->post(route('mail.file', $otherMail))->assertSessionHasNoErrors();

        $this->actingAs($ownCommissioner)->get(route('mail.filed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('mails.data', 1)
                ->where('mails.data.0.id', $ownMail->id));
        $this->actingAs($ownCommissioner)->get(route('mail.show', $otherMail))->assertForbidden();
    }

    public function test_ps_office_correspondence_is_hidden_from_departmental_users_until_it_is_forwarded_to_them(): void
    {
        $department = Department::factory()->create(['code' => 'BE']);
        $unit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'Basic Education Department',
            'code' => 'BE-OFFICE',
            'active' => true,
        ]);
        $ps = User::factory()->role(Role::Ps)->create(['full_name' => 'Kedrace Turyagyenda']);
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $departmentSecretary = User::factory()->role(Role::Secretary)->create(['department_id' => $department->id]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $departmentSecretary->id,
            'supervisor_user_id' => $commissioner->id,
            'organizational_unit_id' => $unit->id,
            'official_job_title' => 'Department Secretary',
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => true,
            'delegated_permissions' => ['mail.manage', 'mail.assign'],
            'active' => true,
        ]);

        // PS Office correspondence carrying a stray department stamp, which is
        // exactly the shape that used to leak into the departmental register.
        $psMail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
            'department_id' => $department->id,
            'office_supervisor_user_id' => $ps->id,
            'subject' => 'Cabinet memorandum for the Permanent Secretary',
        ]);

        foreach ([$commissioner, $departmentSecretary] as $viewer) {
            $this->actingAs($viewer)->get(route('mail.incoming.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('mails.data', 0));
            $this->actingAs($viewer)->get(route('mail.show', $psMail))
                ->assertForbidden();
        }

        // The Permanent Secretary and the attached PS Office secretary keep
        // full access to the same record.
        $psSecretary = User::factory()->role(Role::Secretary)->create();
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $psSecretary->id,
            'supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
            'official_job_title' => 'Senior Personal Secretary to the Permanent Secretary',
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => true,
            'delegated_permissions' => ['mail.manage', 'mail.assign'],
            'active' => true,
        ]);
        $this->actingAs($ps)->get(route('mail.show', $psMail))->assertOk();
        $this->actingAs($psSecretary)->get(route('mail.show', $psMail))->assertOk();

        // Forwarding it to the department makes it visible there, and only then.
        $this->actingAs($ps)->post(route('mail.assign', $psMail), [
            'action_required' => false,
            'target_type' => 'department',
            'target_department_id' => $department->id,
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $this->actingAs($commissioner)->get(route('mail.show', $psMail))->assertOk();
    }

    public function test_403_response_for_ps_office_correspondence_does_not_expose_its_details(): void
    {
        config(['app.debug' => false]);
        $department = Department::factory()->create(['code' => 'BE']);
        $ps = User::factory()->role(Role::Ps)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $psMail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
            'subject' => 'Restricted staffing recommendation',
            'details' => 'The confidential body of the correspondence.',
            'confidentiality' => 'restricted',
        ]);

        $response = $this->actingAs($commissioner)->get(route('mail.show', $psMail));

        $response->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/error')
                ->where('status', 403)
                ->where('message', 'You do not have permission to view this correspondence.'));
        $response->assertDontSee('Restricted staffing recommendation');
        $response->assertDontSee('The confidential body of the correspondence.');
    }

    public function test_ps_office_correspondence_is_excluded_from_departmental_search_and_dashboard_counts(): void
    {
        $department = Department::factory()->create(['code' => 'BE']);
        $unit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'Basic Education Department',
            'code' => 'BE-OFFICE',
            'active' => true,
        ]);
        $ps = User::factory()->role(Role::Ps)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);

        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
            'department_id' => $department->id,
            'subject' => 'Sensitive establishment review',
        ]);
        $departmental = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $commissioner->id,
            'organizational_unit_id' => $unit->id,
            'department_id' => $department->id,
            'subject' => 'Sensitive school enrolment review',
        ]);

        $this->actingAs($commissioner)->get(route('mail.incoming.index', ['q' => 'Sensitive review']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('mails.data', 1)
                ->where('mails.data.0.id', $departmental->id)
                ->where('stats.incoming_total', 1));
    }

    public function test_authorised_roles_see_the_forward_action_and_others_are_told_why_it_is_unavailable(): void
    {
        $department = Department::factory()->create(['code' => 'BE']);
        $unit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => 'Basic Education Department',
            'code' => 'BE-OFFICE',
            'active' => true,
        ]);
        $ps = User::factory()->role(Role::Ps)->create();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $secretary = User::factory()->role(Role::Secretary)->create(['department_id' => $department->id]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $commissioner->id,
            'organizational_unit_id' => $unit->id,
            'official_job_title' => 'Department Secretary',
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => true,
            'delegated_permissions' => ['mail.manage', 'mail.assign'],
            'active' => true,
        ]);

        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $commissioner->id,
            'organizational_unit_id' => $unit->id,
            'department_id' => $department->id,
        ]);

        foreach ([$ps, $clerk, $commissioner, $secretary] as $viewer) {
            $this->actingAs($viewer)->get(route('mail.show', $mail))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('selectedMail.can_assign', true)
                    ->where('selectedMail.can_file', true)
                    ->where('selectedMail.forward_block_reason', null));
        }

        // A closed correspondence explains itself instead of silently hiding
        // the action.
        Correspondence::whereKey($mail->refresh()->correspondence_id)
            ->update(['current_status' => CorrespondenceLifecycleStatus::Closed->value]);

        $this->actingAs($commissioner)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.can_assign', false)
                ->where('selectedMail.forward_block_reason', 'This correspondence has been closed and cannot be forwarded unless it is reopened first.'));
    }

    public function test_filed_correspondence_menu_is_offered_to_registry_roles(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();

        $this->actingAs($ps)->get(route('mail.filed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('nav', fn ($items) => collect($items)->contains(
                    fn ($item) => $item['key'] === 'filed'
                        && $item['label'] === 'Filed Correspondence'
                        && $item['href'] === route('mail.filed.index')
                        && $item['active'] === true,
                )));
    }

    public function test_officers_without_registry_access_cannot_open_the_filed_register_or_file_correspondence(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
        ]);

        $this->actingAs($officer)->get(route('mail.filed.index'))->assertForbidden();
        $this->actingAs($officer)->post(route('mail.file', $mail))->assertForbidden();
        $this->actingAs($officer)->post(route('mail.reopen', $mail))->assertForbidden();
    }

    public function test_already_filed_correspondence_cannot_be_filed_again(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $this->psOffice()->id,
        ]);

        $this->actingAs($ps)->post(route('mail.file', $mail))->assertSessionHasNoErrors();
        $this->actingAs($ps)->post(route('mail.file', $mail))->assertForbidden();

        $this->assertSame(1, CorrespondenceUpdate::where('type', 'filed')->count());
        $this->assertSame(
            1,
            AuditLog::where('target_type', 'MailRecord')
                ->where('target_id', $mail->id)
                ->where('action', "Filed correspondence {$mail->register_number}")
                ->count(),
        );
    }
}
