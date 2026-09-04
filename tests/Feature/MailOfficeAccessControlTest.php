<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AssignmentParticipant;
use App\Models\CorrespondenceAccessGrant;
use App\Models\CorrespondenceForward;
use App\Models\CorrespondenceRecipient;
use App\Models\Department;
use App\Models\MailAttachment;
use App\Models\MailRecord;
use App\Models\Notification;
use App\Models\OrganizationalUnit;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailOfficeAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Cache::flush();
    }

    public function test_rose_nanteza_and_department_head_see_the_register_while_officers_require_explicit_access(): void
    {
        Storage::fake('mail');

        $roseDepartment = Department::factory()->create(['name' => 'Rose Department', 'code' => 'ROSE']);
        $otherDepartment = Department::factory()->create(['name' => 'Other Department', 'code' => 'OTHER']);
        $roseOffice = $this->departmentOffice($roseDepartment, 'ROSE-OFFICE');
        $otherOffice = $this->departmentOffice($otherDepartment, 'OTHER-OFFICE');
        $psOffice = OrganizationalUnit::query()->firstOrCreate(
            ['code' => 'OPS'],
            [
                'type' => 'executive_office',
                'name' => 'Office of the Permanent Secretary',
                'active' => true,
            ],
        );

        $ps = User::factory()->role(Role::Ps)->create(['full_name' => 'Permanent Secretary']);
        $rose = User::factory()->role(Role::Secretary)->create([
            'full_name' => 'Rose Nanteza',
            'department_id' => $roseDepartment->id,
        ]);
        $roseHead = User::factory()->role(Role::Commissioner)->create(['department_id' => $roseDepartment->id]);
        $roseColleague = User::factory()->role(Role::Officer)->create(['department_id' => $roseDepartment->id]);
        $otherHead = User::factory()->role(Role::Commissioner)->create(['department_id' => $otherDepartment->id]);
        $otherOfficer = User::factory()->role(Role::Officer)->create(['department_id' => $otherDepartment->id]);
        $roseDepartment->update(['head_user_id' => $roseHead->id, 'head_name' => $roseHead->full_name]);
        $otherDepartment->update(['head_user_id' => $otherHead->id, 'head_name' => $otherHead->full_name]);

        $roseMail = MailRecord::factory()->incoming()->create([
            'subject' => 'Rose Department Visible Marker',
            'department_id' => $roseDepartment->id,
            'organizational_unit_id' => $roseOffice->id,
            'office_supervisor_user_id' => $roseHead->id,
        ]);
        $otherMail = MailRecord::factory()->incoming()->create([
            'subject' => 'Other Department Private Marker',
            'department_id' => $otherDepartment->id,
            'organizational_unit_id' => $otherOffice->id,
            'office_supervisor_user_id' => $otherHead->id,
        ]);
        $psMail = MailRecord::factory()->incoming()->create([
            'subject' => 'PS Office Private Marker',
            'department_id' => null,
            'organizational_unit_id' => $psOffice->id,
            'office_supervisor_user_id' => $ps->id,
        ]);

        $this->assertTrue($rose->can('view', $roseMail));
        $this->assertFalse($rose->can('view', $otherMail));
        $this->assertFalse($rose->can('view', $psMail));
        $this->assertTrue($roseHead->can('view', $roseMail));
        $this->assertFalse($roseHead->can('view', $otherMail));
        $this->assertFalse($roseColleague->can('view', $roseMail));
        $this->assertTrue($otherHead->can('view', $otherMail));
        $this->assertFalse($otherOfficer->can('view', $otherMail));
        $this->assertTrue($ps->can('view', $psMail));
        $this->assertTrue($ps->can('view', $roseMail));
        $this->assertTrue($ps->can('view', $otherMail));

        $this->actingAs($rose)->get(route('mail.show', $psMail))->assertForbidden();
        $this->actingAs($rose)->getJson(route('mail.recipient-search', $psMail))->assertForbidden();

        Storage::disk('mail')->put('ps/private.pdf', 'private');
        $attachment = MailAttachment::create([
            'mail_record_id' => $psMail->id,
            'original_filename' => 'private.pdf',
            'storage_key' => 'ps/private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'checksum' => hash('sha256', 'private'),
            'uploaded_by_user_id' => $ps->id,
            'uploaded_at' => now(),
        ]);
        $this->actingAs($rose)->get(route('mail.attachments.download', $attachment))->assertForbidden();

        Notification::create([
            'user_id' => $rose->id,
            'type' => 'correspondence_forwarded',
            'category' => 'correspondence_updates',
            'message' => 'PS Office Private Marker',
            'related_mail_record_id' => $psMail->id,
            'is_read' => false,
            'created_at' => now(),
        ]);

        $this->actingAs($rose)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.received_total', 1)
                ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$roseMail->id]));
        $this->actingAs($rose)->get(route('correspondence.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$roseMail->id]));
        $this->actingAs($rose)->get(route('home', ['q' => 'PS Office Private Marker', 'type' => 'mail']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.counts.mails', 0)
                ->has('results.mails', 0)
                ->has('notifications.items', 0));
        $this->actingAs($rose)->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('correspondenceSummary.total', 1));

        $forward = CorrespondenceForward::create([
            'correspondence_id' => $psMail->correspondence_id,
            'forwarded_by_user_id' => $ps->id,
            'from_organizational_unit_id' => $psOffice->id,
            'instructions' => 'Shared through the normal correspondence workflow.',
            'status' => 'sent',
            'forwarded_at' => now(),
        ]);
        CorrespondenceRecipient::create([
            'correspondence_id' => $psMail->correspondence_id,
            'correspondence_forward_id' => $forward->id,
            'recipient_type' => 'to',
            'purpose' => 'information',
            'target_type' => 'individual',
            'user_id' => $rose->id,
            'recipient_name_snapshot' => $rose->full_name,
            'active' => true,
            'added_by_user_id' => $ps->id,
            'added_at' => now(),
        ]);

        $this->assertTrue($rose->can('view', $psMail));
        $this->assertFalse($roseColleague->can('view', $psMail));
        $this->actingAs($rose)->get(route('mail.show', $psMail))->assertOk();
        $this->actingAs($rose)->get(route('mail.attachments.download', $attachment))->assertOk();

        CorrespondenceRecipient::create([
            'correspondence_id' => $psMail->correspondence_id,
            'correspondence_forward_id' => $forward->id,
            'recipient_type' => 'cc',
            'purpose' => 'information',
            'target_type' => 'department',
            'department_id' => $roseDepartment->id,
            'recipient_name_snapshot' => $roseDepartment->name,
            'active' => true,
            'added_by_user_id' => $ps->id,
            'added_at' => now(),
        ]);
        $this->assertTrue($roseHead->can('view', $psMail));
        $this->assertFalse($roseColleague->can('view', $psMail));

        CorrespondenceAccessGrant::create([
            'correspondence_id' => $otherMail->correspondence_id,
            'user_id' => $rose->id,
            'access_level' => 'read',
            'granted_by_user_id' => $otherOfficer->id,
            'granted_at' => now(),
            'reason' => 'Explicit workflow exception.',
        ]);
        $this->assertTrue($rose->can('view', $otherMail));
        $this->assertFalse($roseColleague->can('view', $otherMail));
    }

    public function test_direct_office_and_department_assignments_grant_only_the_targeted_scope(): void
    {
        $roseDepartment = Department::factory()->create(['name' => 'Rose Department', 'code' => 'ROSE']);
        $otherDepartment = Department::factory()->create(['name' => 'Other Department', 'code' => 'OTHER']);
        $psOffice = OrganizationalUnit::query()->firstOrCreate(
            ['code' => 'OPS'],
            ['type' => 'executive_office', 'name' => 'Office of the Permanent Secretary', 'active' => true],
        );
        $ps = User::factory()->role(Role::Ps)->create();
        $rose = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Rose Nanteza',
            'department_id' => $roseDepartment->id,
        ]);
        $roseColleague = User::factory()->role(Role::Officer)->create(['department_id' => $roseDepartment->id]);
        $otherOfficer = User::factory()->role(Role::Officer)->create(['department_id' => $otherDepartment->id]);
        $roseHead = User::factory()->role(Role::Commissioner)->create(['department_id' => $roseDepartment->id]);
        $roseSecretary = User::factory()->role(Role::Secretary)->create(['department_id' => $roseDepartment->id]);
        $roseDepartment->update(['head_user_id' => $roseHead->id, 'head_name' => $roseHead->full_name]);

        $directTask = Task::factory()->create([
            'assignment_target_type' => 'individual',
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $rose->id,
            'current_assignee_user_id' => $rose->id,
            'responsible_user_id' => $rose->id,
            'department_id' => $roseDepartment->id,
        ]);
        $directMail = MailRecord::factory()->incoming()->create([
            'subject' => 'Direct Rose Assignment',
            'department_id' => null,
            'organizational_unit_id' => $psOffice->id,
            'office_supervisor_user_id' => $ps->id,
            'task_id' => $directTask->id,
        ]);

        $this->assertTrue($rose->can('view', $directMail));
        $this->assertFalse($roseColleague->can('view', $directMail));
        $this->assertFalse($otherOfficer->can('view', $directMail));
        $this->actingAs($rose)->get(route('home', ['q' => 'Direct Rose Assignment', 'type' => 'mail']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.counts.mails', 1)
                ->where('results.mails.0.id', $directMail->id));

        $departmentTask = Task::factory()->create([
            'assignment_target_type' => 'department',
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => null,
            'assigned_to_department_id' => $roseDepartment->id,
            'department_id' => $roseDepartment->id,
        ]);
        $departmentMail = MailRecord::factory()->incoming()->create([
            'subject' => 'Rose Department Assignment',
            'department_id' => null,
            'organizational_unit_id' => $psOffice->id,
            'office_supervisor_user_id' => $ps->id,
            'task_id' => $departmentTask->id,
        ]);

        $this->assertFalse($rose->can('view', $departmentMail));
        $this->assertFalse($roseColleague->can('view', $departmentMail));
        $this->assertTrue($roseHead->can('view', $departmentMail));
        $this->assertTrue($roseSecretary->can('view', $departmentMail));
        $this->assertFalse($otherOfficer->can('view', $departmentMail));

        AssignmentParticipant::create([
            'task_id' => $departmentTask->id,
            'user_id' => $rose->id,
            'participant_type' => 'assignee',
            'active' => true,
            'assigned_at' => now(),
            'added_by_user_id' => $ps->id,
        ]);

        $this->assertTrue($rose->can('view', $departmentMail));
        $this->assertFalse($roseColleague->can('view', $departmentMail));
    }

    public function test_individual_recipient_placement_metadata_does_not_grant_department_custodians_access(): void
    {
        Storage::fake('mail');

        $department = Department::factory()->create(['name' => 'Private Recipient Department', 'code' => 'PRIVATE']);
        $psOffice = OrganizationalUnit::query()->firstOrCreate(
            ['code' => 'OPS'],
            ['type' => 'executive_office', 'name' => 'Office of the Permanent Secretary', 'active' => true],
        );
        $clerk = User::factory()->role(Role::Clerk)->create();
        $recipient = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $departmentHead = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $department->update(['head_user_id' => $departmentHead->id, 'head_name' => $departmentHead->full_name]);

        $individualMail = MailRecord::factory()->incoming()->create([
            'subject' => 'Individually Addressed Confidential Mail',
            'captured_by_user_id' => $clerk->id,
            'department_id' => null,
            'organizational_unit_id' => $psOffice->id,
        ]);

        $this->actingAs($clerk)->post(route('mail.assign', $individualMail), [
            'assigned_to_user_id' => $recipient->id,
            'action_required' => false,
            'priority' => 'medium',
            'instructions' => 'For the named recipient only.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_id' => $individualMail->correspondence_id,
            'target_type' => 'individual',
            'user_id' => $recipient->id,
            'department_id' => $department->id,
        ]);

        Storage::disk('mail')->put('confidential/individual.txt', 'recipient-only');
        $attachment = MailAttachment::create([
            'mail_record_id' => $individualMail->id,
            'original_filename' => 'individual.txt',
            'storage_key' => 'confidential/individual.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 14,
            'checksum' => hash('sha256', 'recipient-only'),
            'uploaded_by_user_id' => $clerk->id,
            'uploaded_at' => now(),
        ]);

        $this->assertTrue($recipient->can('view', $individualMail));
        $this->assertFalse($departmentHead->can('view', $individualMail));
        $this->actingAs($recipient)->get(route('mail.show', $individualMail))->assertOk();
        $this->actingAs($recipient)->get(route('mail.attachments.download', $attachment))->assertOk();
        $this->actingAs($departmentHead)->get(route('mail.show', $individualMail))->assertForbidden();
        $this->actingAs($departmentHead)->get(route('mail.attachments.download', $attachment))->assertForbidden();
        $this->actingAs($departmentHead)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($individualMail->id)));

        $departmentMail = MailRecord::factory()->incoming()->create([
            'subject' => 'Explicit Department Correspondence',
            'captured_by_user_id' => $clerk->id,
            'department_id' => null,
            'organizational_unit_id' => $psOffice->id,
        ]);
        $this->actingAs($clerk)->post(route('mail.assign', $departmentMail), [
            'target_type' => 'department',
            'target_department_id' => $department->id,
            'action_required' => false,
            'priority' => 'medium',
            'instructions' => 'For the department register.',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($departmentHead->can('view', $departmentMail));
        $this->actingAs($departmentHead)->get(route('mail.show', $departmentMail))->assertOk();
    }

    private function departmentOffice(Department $department, string $code): OrganizationalUnit
    {
        return OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => $department->name.' Office',
            'code' => $code,
            'active' => true,
        ]);
    }
}
