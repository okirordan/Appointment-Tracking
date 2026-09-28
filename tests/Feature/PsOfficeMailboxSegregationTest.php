<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AnnotationTitle;
use App\Models\CorrespondenceForward;
use App\Models\CorrespondenceRecipient;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role as PermissionRole;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use App\Models\UserPosition;
use App\Services\Mail\MailboxScope;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PsOfficeMailboxSegregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_secretary_mail_does_not_enter_ps_register_by_naming_ps_as_a_party(): void
    {
        $this->seed(RoleSeeder::class);
        $psOffice = OrganizationalUnit::firstOrCreate(
            ['code' => 'OPS'],
            ['name' => 'Office of the Permanent Secretary', 'type' => 'executive_office', 'active' => true],
        );
        $department = Department::factory()->create();
        $departmentOffice = OrganizationalUnit::create([
            'code' => 'DEPT-RECORDER', 'name' => 'Recorder Department',
            'type' => 'department', 'department_id' => $department->id, 'active' => true,
        ]);
        $ps = User::factory()->role(Role::Ps)->create(['organizational_unit_id' => $psOffice->id]);
        $psOfficial = User::factory()->role(Role::Officer)->create(['organizational_unit_id' => $psOffice->id]);
        $appointedPsOfficial = User::factory()->role(Role::Officer)->create([
            'organizational_unit_id' => $departmentOffice->id,
        ]);
        $psOfficialPosition = Position::create([
            'organizational_unit_id' => $psOffice->id,
            'role_id' => PermissionRole::query()->where('name', Role::Officer->value)->value('id'),
            'title' => 'Permanent Secretary Official', 'hierarchy_level' => 20, 'active' => true,
        ]);
        UserPosition::create([
            'user_id' => $appointedPsOfficial->id, 'position_id' => $psOfficialPosition->id,
            'is_primary' => true, 'active' => true, 'starts_at' => now()->subMinute(),
        ]);
        $movedOfficial = User::factory()->role(Role::Officer)->create(['organizational_unit_id' => $psOffice->id]);
        $departmentPosition = Position::create([
            'organizational_unit_id' => $departmentOffice->id,
            'role_id' => PermissionRole::query()->where('name', Role::Officer->value)->value('id'),
            'title' => 'Department Official', 'hierarchy_level' => 20, 'active' => true,
        ]);
        UserPosition::create([
            'user_id' => $movedOfficial->id, 'position_id' => $departmentPosition->id,
            'is_primary' => true, 'active' => true, 'starts_at' => now()->subMinute(),
        ]);
        $departmentOfficial = User::factory()->role(Role::Officer)->create(['organizational_unit_id' => $departmentOffice->id]);
        $psSecretary = User::factory()->role(Role::Secretary)->create(['organizational_unit_id' => $psOffice->id]);
        $secondPsSecretary = User::factory()->role(Role::Secretary)->create();
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $psSecretary->id, 'supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $psOffice->id, 'official_job_title' => 'PS Secretary',
            'starts_at' => now()->subMinute(), 'active' => true,
        ]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secondPsSecretary->id, 'supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $psOffice->id, 'official_job_title' => 'PS Office Secretary',
            'starts_at' => now()->subMinute(), 'active' => true,
        ]);
        $departmentSecretary = User::factory()->role(Role::Secretary)->create([
            'department_id' => $department->id, 'organizational_unit_id' => $departmentOffice->id,
        ]);
        $departmentAttachedSecretary = User::factory()->role(Role::Secretary)->create([
            'department_id' => $department->id, 'organizational_unit_id' => $departmentOffice->id,
        ]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $departmentAttachedSecretary->id, 'supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $departmentOffice->id, 'official_job_title' => 'Department Secretary',
            'starts_at' => now()->subMinute(), 'active' => true,
        ]);
        $this->assertFalse(app(MailboxScope::class)->isPsOfficeSecretary($departmentAttachedSecretary));

        $this->actingAs($departmentSecretary)->post(route('mail.incoming.store'), [
            'sender_name' => 'Office of the Permanent Secretary',
            'recipient_name' => 'Permanent Secretary',
            'subject' => 'Department-recorded letter to PS',
            'received_date' => now()->toDateString(),
            'confidentiality' => 'normal',
            'priority' => 'medium',
            'status' => 'registered',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $departmentIncoming = MailRecord::query()->where('subject', 'Department-recorded letter to PS')->firstOrFail();
        $this->actingAs($departmentSecretary)->post(route('mail.outgoing.store'), [
            'sender_name' => 'Permanent Secretary',
            'recipient_name' => 'Auditor General',
            'subject' => 'Department-recorded letter from PS',
            'sent_date' => now()->toDateString(),
            'confidentiality' => 'normal',
            'priority' => 'medium',
            'status' => 'dispatched',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $departmentOutgoing = MailRecord::query()->where('subject', 'Department-recorded letter from PS')->firstOrFail();
        $this->assertSame($departmentSecretary->id, $departmentIncoming->captured_by_user_id);
        $this->assertSame($departmentOffice->id, $departmentIncoming->organizational_unit_id);
        $this->assertSame($departmentOffice->id, $departmentOutgoing->organizational_unit_id);
        $psIncoming = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $psSecretary->id,
            'organizational_unit_id' => $psOffice->id,
            'recipient_name' => 'Permanent Secretary',
        ]);
        $psOutgoing = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $ps->id,
            'organizational_unit_id' => $psOffice->id,
            'sender_name' => 'Permanent Secretary',
            'recipient_name' => 'Auditor General',
        ]);

        foreach ([$ps, $psSecretary, $secondPsSecretary, $psOfficial, $appointedPsOfficial] as $viewer) {
            $this->actingAs($viewer)->get(route('mail.incoming.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$psIncoming->id])
                    ->where('stats.received_total', 1));
            $this->actingAs($viewer)->get(route('mail.outgoing.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$psOutgoing->id]));
        }

        $this->actingAs($psSecretary)->get(route('mail.show', $departmentIncoming))->assertForbidden();
        $this->actingAs($psSecretary)->get(route('mail.show', $departmentOutgoing))->assertForbidden();
        $this->actingAs($psOfficial)->get(route('mail.show', $psIncoming))->assertOk();
        $this->actingAs($psOfficial)->get(route('mail.show', $departmentIncoming))->assertForbidden();
        $this->actingAs($psOfficial)->get(route('mail.incoming.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManageRegister', false)
                ->where('nav', fn ($items) => collect($items)->contains('key', 'mail')));
        $this->actingAs($psOfficial)->post(route('mail.incoming.store'), [])->assertForbidden();
        $this->actingAs($departmentOfficial)->get(route('mail.incoming.index'))->assertForbidden();
        $this->actingAs($movedOfficial)->get(route('mail.incoming.index'))->assertForbidden();
        $this->actingAs($departmentSecretary)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->contains($departmentIncoming->id)));
        $this->actingAs($departmentSecretary)->get(route('mail.outgoing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->contains($departmentOutgoing->id)));
    }

    public function test_ps_office_registers_exclude_department_correspondence_in_both_directions(): void
    {
        $this->seed(RoleSeeder::class);
        $psOffice = OrganizationalUnit::firstOrCreate(
            ['code' => 'OPS'],
            ['name' => 'Office of the Permanent Secretary', 'type' => 'executive_office', 'active' => true],
        );
        $department = Department::factory()->create();
        $departmentOffice = OrganizationalUnit::create([
            'code' => 'DEPT-TEST', 'name' => 'Department Office',
            'type' => 'department', 'department_id' => $department->id, 'active' => true,
        ]);
        $ps = User::factory()->role(Role::Ps)->create(['organizational_unit_id' => $psOffice->id]);
        $clerk = User::factory()->role(Role::Clerk)->create(['organizational_unit_id' => $psOffice->id]);
        $secretary = User::factory()->role(Role::Secretary)->create(['organizational_unit_id' => $psOffice->id]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id, 'supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $psOffice->id, 'official_job_title' => 'PS Secretary',
            'starts_at' => now()->subMinute(), 'active' => true,
        ]);
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);

        $psIncoming = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretary->id, 'organizational_unit_id' => $psOffice->id,
            'recipient_name' => 'PS/ES', 'subject' => 'PS incoming',
        ]);
        $copiedPsIncoming = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secretary->id, 'organizational_unit_id' => $psOffice->id,
            'recipient_name' => 'cc PS/ES', 'subject' => 'PS information copy',
        ]);
        $departmentIncoming = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id, 'organizational_unit_id' => $psOffice->id,
            'recipient_name' => 'C/LEIT — Commissioner', 'subject' => 'Department incoming',
        ]);
        $departmentOwnedIncoming = MailRecord::factory()->incoming()->create([
            'organizational_unit_id' => $departmentOffice->id, 'department_id' => $department->id,
            'recipient_name' => 'Commissioner', 'subject' => 'Department owned incoming',
        ]);
        $psOutgoing = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $secretary->id, 'organizational_unit_id' => $psOffice->id,
            'sender_name' => 'Permanent Secretary', 'recipient_name' => 'Auditor General',
            'subject' => 'PS outgoing',
        ]);
        $importedPsOutgoing = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $clerk->id, 'organizational_unit_id' => $psOffice->id,
            'external_id' => 'mail-manager-outgoing-mhtml-2026-07-23:00001',
            'sender_name' => 'Irene Kauma', 'recipient_name' => 'CHRM',
            'subject' => 'Imported PS Office outgoing register entry',
        ]);
        $departmentOutgoing = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $clerk->id, 'organizational_unit_id' => $psOffice->id,
            'sender_name' => 'Commissioner LEIT', 'recipient_name' => 'Auditor General',
            'subject' => 'Department outgoing',
        ]);
        $departmentOwnedOutgoing = MailRecord::factory()->outgoing()->create([
            'organizational_unit_id' => $departmentOffice->id, 'department_id' => $department->id,
            'sender_name' => 'Commissioner LEIT', 'recipient_name' => 'Auditor General',
            'subject' => 'Department owned outgoing',
        ]);

        foreach ([$ps, $secretary] as $viewer) {
            $this->actingAs($viewer)->get(route('mail.incoming.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$copiedPsIncoming->id, $psIncoming->id])
                    ->where('stats.received_total', 2));
            $this->actingAs($viewer)->get(route('mail.outgoing.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$importedPsOutgoing->id, $psOutgoing->id]));
        }

        $this->actingAs($commissioner)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->contains($departmentOwnedIncoming->id)));
        $this->actingAs($commissioner)->get(route('mail.outgoing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->contains($departmentOwnedOutgoing->id)));
        $this->actingAs($secretary)->get(route('mail.show', $departmentIncoming))->assertForbidden();
        $this->actingAs($secretary)->get(route('mail.show', $departmentOutgoing))->assertForbidden();
        $this->actingAs($secretary)->get(route('mail.show', $psOutgoing))->assertOk();
        $this->actingAs($secretary)->get(route('mail.show', $importedPsOutgoing))->assertOk();
        $this->actingAs($secretary)->get(route('home', ['q' => 'Department incoming', 'type' => 'mail']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('results.counts.mails', 0));
        $this->assertTrue($ps->can('view', $departmentIncoming));
        $this->assertTrue($ps->can('view', $departmentOutgoing));
    }

    public function test_structured_ps_identity_and_outgoing_on_behalf_of_ps_remain_in_the_registers(): void
    {
        $this->seed(RoleSeeder::class);
        $psOffice = OrganizationalUnit::firstOrCreate(
            ['code' => 'OPS'],
            ['name' => 'Office of the Permanent Secretary', 'type' => 'executive_office', 'active' => true],
        );
        $ps = User::factory()->role(Role::Ps)->create(['organizational_unit_id' => $psOffice->id]);
        $secretary = User::factory()->role(Role::Secretary)->create(['organizational_unit_id' => $psOffice->id]);
        $psTitle = AnnotationTitle::query()->firstOrCreate(
            ['normalized_shorthand' => 'pses'],
            ['shorthand' => 'PS/ES', 'full_title' => 'Permanent Secretary / Education and Sports', 'active' => true],
        );
        $psMail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_annotation_title_id' => $psTitle->id,
            'recipient_name' => 'Historical title snapshot',
        ]);
        $otherMinistryMail = MailRecord::factory()->incoming()->create([
            'recipient_name' => 'Permanent Secretary, Ministry of Gender',
        ]);
        $copiedToPsMail = MailRecord::factory()->incoming()->create([
            'recipient_name' => 'Commissioner LEIT',
        ]);
        $forward = CorrespondenceForward::create([
            'correspondence_id' => $copiedToPsMail->correspondence_id,
            'forwarded_by_user_id' => $secretary->id,
            'status' => 'sent', 'forwarded_at' => now(),
        ]);
        CorrespondenceRecipient::create([
            'correspondence_id' => $copiedToPsMail->correspondence_id,
            'correspondence_forward_id' => $forward->id,
            'recipient_type' => 'cc', 'purpose' => 'information',
            'target_type' => 'individual', 'user_id' => $ps->id,
            'recipient_name_snapshot' => $ps->full_name,
            'active' => true, 'added_by_user_id' => $secretary->id, 'added_at' => now(),
        ]);
        $psOutgoing = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $secretary->id,
            'organizational_unit_id' => $psOffice->id,
            'sender_name' => $secretary->full_name,
            'prepared_on_behalf_of_user_id' => $ps->id,
            'recipient_name' => 'Auditor General',
        ]);

        $this->actingAs($ps)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$psMail->id]));
        $this->actingAs($ps)->get(route('mail.outgoing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$psOutgoing->id]));
        $this->assertTrue($ps->can('view', $otherMinistryMail));
    }
}
