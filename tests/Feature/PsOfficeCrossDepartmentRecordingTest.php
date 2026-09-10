<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\EnsureAccountAccessIsCurrent;
use App\Models\AuditLog;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\RecipientAlias;
use App\Models\Role as PermissionRole;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPosition;
use App\Services\Mail\MailFeatureSettings;
use App\Services\ReportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PsOfficeCrossDepartmentRecordingTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $psOffice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountAccessIsCurrent::class);
        $this->seed(RoleSeeder::class);
        $this->psOffice = OrganizationalUnit::query()->where('code', 'OPS')->firstOrFail();
    }

    public function test_feature_is_disabled_by_default_and_requires_both_ps_office_membership_and_the_dedicated_permission(): void
    {
        $mail = $this->psMail();
        $authorized = $this->psRecorder('Authorized PS registry officer');
        $missingPermission = User::factory()->role(Role::Secretary)->create([
            'organizational_unit_id' => $this->psOffice->id,
        ]);
        [, $departmentUnit] = $this->departmentUnit('Basic Education', 'BE');
        $wrongOffice = User::factory()->role(Role::Secretary)->create([
            'organizational_unit_id' => $departmentUnit->id,
        ]);

        $authorized->givePermissionTo('ps_office_cross_department_recording');
        $wrongOffice->givePermissionTo('ps_office_cross_department_recording');

        $payload = $this->movementPayload($departmentUnit, 'ps_to_department');
        $this->actingAs($authorized)->post(route('mail.department-interactions.store', $mail), $payload)->assertForbidden();

        app(MailFeatureSettings::class)->set('ps_office_cross_department_recording', true);

        $this->actingAs($missingPermission)->post(route('mail.department-interactions.store', $mail), $payload)->assertForbidden();
        $this->actingAs($wrongOffice)->post(route('mail.department-interactions.store', $mail), $payload)->assertForbidden();
        $this->assertCount(11, app(MailFeatureSettings::class)->definitions());
        $this->actingAs($authorized)->post(route('mail.department-interactions.store', $mail), [
            ...$payload,
            'action_type' => 'returned_to_ps',
        ])->assertSessionHasErrors('action_type');
        $ministryRoot = OrganizationalUnit::create([
            'type' => 'ministry',
            'name' => 'Ministry Root',
            'code' => 'MINISTRY-ROOT',
            'active' => true,
        ]);
        $this->actingAs($authorized)->post(route('mail.department-interactions.store', $mail), [
            ...$payload,
            'organizational_unit_id' => $ministryRoot->id,
        ])->assertSessionHasErrors('organizational_unit_id');
        $this->actingAs($authorized)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.can_record_department_interaction', true));
    }

    public function test_authorized_recorder_searches_the_forwarding_directory_by_officer_name_and_shorthand(): void
    {
        app(MailFeatureSettings::class)->set('ps_office_cross_department_recording', true);
        $recorder = $this->psRecorder();
        $recorder->givePermissionTo('ps_office_cross_department_recording');
        [$department, $unit] = $this->departmentUnit('Libraries, E-Learning and Information Technology', 'LEIT');
        $position = Position::create([
            'organizational_unit_id' => $unit->id,
            'role_id' => PermissionRole::query()->where('name', Role::Commissioner->value)->value('id'),
            'title' => 'Commissioner – Library, E-Learning and Information Technology',
            'hierarchy_level' => 20,
            'active' => true,
        ]);
        $officer = User::factory()->role(Role::Commissioner)->create([
            'full_name' => 'Patrick Emmanuel Muinda',
            'title' => 'Stale imported title',
            'department_id' => $department->id,
        ]);
        UserPosition::create([
            'user_id' => $officer->id,
            'position_id' => $position->id,
            'is_primary' => true,
            'active' => true,
            'starts_at' => now()->subDay(),
        ]);
        RecipientAlias::create([
            'alias' => 'C/LEIT',
            'target_type' => Position::class,
            'target_id' => $position->id,
            'active' => true,
        ]);
        $mail = $this->psMail();

        foreach (['Patrick Muinda', 'C/LEIT'] as $query) {
            $this->actingAs($recorder)
                ->getJson(route('mail.department-interactions.recipient-search', ['mail' => $mail, 'q' => $query]))
                ->assertOk()
                ->assertJsonPath('recipients.0.id', $officer->id)
                ->assertJsonPath('recipients.0.organizational_unit_id', $unit->id)
                ->assertJsonPath('recipients.0.title', $position->title)
                ->assertJsonPath('recipients.0.department', $department->name)
                ->assertJsonPath('recipients.0.shorthand_code', 'C/LEIT');
        }

        $unauthorized = $this->psRecorder('Unauthorized PS registry officer');
        $this->actingAs($unauthorized)
            ->getJson(route('mail.department-interactions.recipient-search', ['mail' => $mail, 'q' => 'Patrick']))
            ->assertForbidden();
    }

    public function test_ps_recorder_can_capture_repeated_cycles_on_one_correspondence_with_current_holder_and_scoped_history(): void
    {
        Storage::fake('mail');
        Carbon::setTestNow('2026-09-09 14:30:00');
        app(MailFeatureSettings::class)->set('ps_office_cross_department_recording', true);
        $recorder = $this->psRecorder();
        $recorder->givePermissionTo('ps_office_cross_department_recording');
        [$departmentA, $unitA] = $this->departmentUnit('Basic Education', 'BE');
        [, $unitB] = $this->departmentUnit('Higher Education', 'HE');
        $secretaryA = User::factory()->role(Role::Secretary)->create(['organizational_unit_id' => $unitA->id]);
        $secretaryB = User::factory()->role(Role::Secretary)->create(['organizational_unit_id' => $unitB->id]);
        $responsibleOfficerA = User::factory()->role(Role::Officer)->create([
            'department_id' => $departmentA->id,
            'organizational_unit_id' => $unitA->id,
        ]);
        $mail = $this->psMail(['subject' => 'Education financing response']);

        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), [
            ...$this->movementPayload($unitA, 'ps_to_department'),
            'occurred_at' => '2026-09-01 09:15:00',
            'annotation' => 'Review and provide comments.',
            'responsible_user_id' => $responsibleOfficerA->id,
            'attachments' => [UploadedFile::fake()->create('instruction.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $mail->refresh();
        $this->assertDatabaseCount('mail_records', 1);
        $this->assertSame($unitA->id, $mail->correspondence->current_holder_organizational_unit_id);
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'forwarded_for_action',
            'from_organizational_unit_id' => $this->psOffice->id,
            'to_organizational_unit_id' => $unitA->id,
            'represented_organizational_unit_id' => $this->psOffice->id,
            'performed_by_user_id' => $recorder->id,
            'entry_method' => 'ps_cross_department',
            'occurred_at' => '2026-09-01 09:15:00',
            'recorded_at' => '2026-09-09 14:30:00',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $secretaryA->id,
            'type' => 'ps_cross_department_movement',
            'message' => 'The Office of the Permanent Secretary recorded this correspondence as forwarded to Basic Education for action.',
        ]);
        $departmentAAttachment = CorrespondenceAttachment::query()->sole();
        $this->actingAs($secretaryA)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('mails.data.0.id', $mail->id));

        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), [
            ...$this->movementPayload($unitA, 'department_to_ps'),
            'action_type' => 'returned_to_ps',
            'status_after' => 'responded',
            'occurred_at' => '2026-09-03 16:45:00',
            'annotation' => 'Department response received with supporting comments.',
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->psOffice->id, $mail->fresh()->correspondence->current_holder_organizational_unit_id);
        $this->assertDatabaseHas('correspondence_updates', [
            'from_organizational_unit_id' => $unitA->id,
            'to_organizational_unit_id' => $this->psOffice->id,
            'represented_organizational_unit_id' => $unitA->id,
            'performed_by_user_id' => $recorder->id,
        ]);
        $this->actingAs($secretaryA)->get(route('mail.outgoing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('mails.data.0.id', $mail->id));

        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), [
            ...$this->movementPayload($unitB, 'ps_to_department'),
            'occurred_at' => '2026-09-05 10:00:00',
            'annotation' => 'Provide technical input.',
        ])->assertSessionHasNoErrors();
        $internalUpdate = CorrespondenceUpdate::create([
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'note',
            'body' => 'Internal PS note outside the historical participation grant.',
            'performed_by_user_id' => $recorder->id,
            'performed_by_name_snapshot' => $recorder->full_name,
        ]);
        $internalStorageKey = "correspondence/{$mail->correspondence_id}/internal-note.pdf";
        Storage::disk('mail')->put($internalStorageKey, 'restricted internal note');
        $internalAttachment = CorrespondenceAttachment::create([
            'correspondence_id' => $mail->correspondence_id,
            'correspondence_update_id' => $internalUpdate->id,
            'version_group' => 'internal-note-test',
            'version_number' => 1,
            'status' => 'active',
            'original_filename' => 'internal-note.pdf',
            'storage_key' => $internalStorageKey,
            'mime_type' => 'application/pdf',
            'size_bytes' => 24,
            'checksum' => hash('sha256', 'restricted internal note'),
            'uploaded_by_user_id' => $recorder->id,
            'uploaded_at' => now(),
        ]);

        $this->actingAs($secretaryA)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('selectedMail.interaction_history', 2)
                ->has('selectedMail.attachments', 1)
                ->where('selectedMail.activity_history', fn ($history) => collect($history)
                    ->doesntContain('message', 'Internal PS note outside the historical participation grant.'))
                ->where('selectedMail.direct_action_count', 0)
                ->where('selectedMail.interaction_history.0.to', 'Basic Education')
                ->where('selectedMail.interaction_history.1.from', 'Basic Education'));
        $this->actingAs($secretaryB)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('selectedMail.interaction_history', 1)
                ->has('selectedMail.attachments', 1)
                ->where('selectedMail.assignment', null)
                ->where('selectedMail.task_url', null)
                ->where('selectedMail.direct_action_count', 1)
                ->where('selectedMail.interaction_history.0.to', 'Higher Education')
                ->where('selectedMail.current_holder', 'Higher Education'));
        $this->actingAs($secretaryB)->get(route('correspondence.attachments.download', $departmentAAttachment))->assertForbidden();
        $this->actingAs($secretaryA)->get(route('correspondence.attachments.download', $departmentAAttachment))->assertOk();
        $this->actingAs($secretaryA)->get(route('correspondence.attachments.download', $internalAttachment))->assertForbidden();
        $this->actingAs($secretaryB)->get(route('correspondence.attachments.download', $internalAttachment))->assertOk();
        $this->actingAs($recorder)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('selectedMail.interaction_history', 3)
                ->where('selectedMail.activity_history', fn ($history) => collect($history)
                    ->contains(fn ($entry) => $entry['message'] === 'Review and provide comments.'
                        && $entry['origin_title'] === 'PS'
                        && $entry['recipient_title'] === 'C/BE'))
                ->where('selectedMail.activity_history', fn ($history) => collect($history)
                    ->contains(fn ($entry) => $entry['message'] === 'Department response received with supporting comments.'
                        && $entry['origin_title'] === 'C/BE'
                        && $entry['recipient_title'] === 'PS')));

        $departmentASummary = app(ReportService::class)->build($secretaryA)['correspondenceSummary'];
        $this->assertSame(0, $departmentASummary['direct_actions']);
        $this->assertSame(2, $departmentASummary['reconstructed_entries']);
        $this->assertSame(2, $departmentASummary['movement_count']);
        $departmentBSummary = app(ReportService::class)->build($secretaryB)['correspondenceSummary'];
        $this->assertSame(1, $departmentBSummary['direct_actions']);
        $this->assertSame(1, $departmentBSummary['reconstructed_entries']);
        $this->assertSame(1, $departmentBSummary['movement_count']);

        $this->assertCount(3, AuditLog::query()
            ->where('target_type', 'CorrespondenceUpdate')
            ->whereJsonContains('metadata_json->entry_method', 'ps_cross_department')
            ->get());
        Carbon::setTestNow();
    }

    public function test_likely_duplicate_requires_explicit_confirmation_but_legitimate_repeat_movements_are_allowed(): void
    {
        Carbon::setTestNow('2026-09-09 10:00:00');
        app(MailFeatureSettings::class)->set('ps_office_cross_department_recording', true);
        $recorder = $this->psRecorder();
        $recorder->givePermissionTo('ps_office_cross_department_recording');
        [, $unit] = $this->departmentUnit('Teacher Education', 'TE');
        $mail = $this->psMail();
        $payload = [
            ...$this->movementPayload($unit, 'ps_to_department'),
            'occurred_at' => '2026-09-08 09:00:00',
            'annotation' => 'Prepare the implementation response.',
        ];

        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), $payload)->assertSessionHasNoErrors();
        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), $payload)
            ->assertSessionHasErrors('duplicate_confirmation');
        $this->assertDatabaseCount('correspondence_updates', 1);

        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), [
            ...$payload,
            'confirm_duplicate' => true,
        ])->assertSessionHasNoErrors();
        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), [
            ...$payload,
            'occurred_at' => '2026-09-08 12:00:00',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('correspondence_updates', 3);
        Carbon::setTestNow();
    }

    public function test_optional_responsible_officer_creates_a_linked_assignment_without_impersonation(): void
    {
        app(MailFeatureSettings::class)->set('ps_office_cross_department_recording', true);
        $recorder = $this->psRecorder();
        $recorder->givePermissionTo('ps_office_cross_department_recording');
        [$department, $unit] = $this->departmentUnit('Special Needs Education', 'SNE');
        $officer = User::factory()->role(Role::Officer)->create([
            'department_id' => $department->id,
            'organizational_unit_id' => $unit->id,
        ]);
        $mail = $this->psMail();

        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), [
            ...$this->movementPayload($unit, 'ps_to_department'),
            'responsible_user_id' => $officer->id,
            'annotation' => 'Prepare a formal response for the PS.',
        ])->assertSessionHasNoErrors();

        $task = Task::query()->sole();
        $this->assertSame($recorder->id, $task->assigned_by_user_id);
        $this->assertSame($officer->id, $task->assigned_to_user_id);
        $this->assertDatabaseHas('correspondence_updates', [
            'task_id' => $task->id,
            'responsible_user_id' => $officer->id,
            'performed_by_user_id' => $recorder->id,
        ]);
        $this->assertDatabaseHas('correspondence_recipients', [
            'organizational_unit_id' => $unit->id,
            'task_id' => $task->id,
        ]);
    }

    public function test_filing_a_reconstructed_movement_clears_the_current_holder_and_preserves_the_timeline(): void
    {
        app(MailFeatureSettings::class)->set('ps_office_cross_department_recording', true);
        $recorder = $this->psRecorder();
        $recorder->givePermissionTo('ps_office_cross_department_recording');
        [, $unit] = $this->departmentUnit('Policy Analysis', 'PA');
        $mail = $this->psMail();

        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), $this->movementPayload($unit, 'ps_to_department'))
            ->assertSessionHasNoErrors();
        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $mail), [
            ...$this->movementPayload($unit, 'department_to_ps'),
            'action_type' => 'filed',
            'status_after' => 'filed',
            'annotation' => 'Response accepted and correspondence filed.',
        ])->assertSessionHasNoErrors();

        $correspondence = $mail->fresh()->correspondence;
        $this->assertNull($correspondence->current_holder_organizational_unit_id);
        $this->assertSame('filed', $correspondence->current_status->value);
        $this->assertCount(2, $correspondence->updates()->where('entry_method', 'ps_cross_department')->get());
        $this->actingAs($recorder)->get(route('mail.outgoing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('mails.data', 0));
        $this->actingAs($recorder)->get(route('mail.filed.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('mails.data.0.id', $mail->id));
    }

    public function test_reporting_separates_direct_actions_from_reconstructed_movements(): void
    {
        app(MailFeatureSettings::class)->set('ps_office_cross_department_recording', true);
        $recorder = $this->psRecorder();
        $recorder->givePermissionTo('ps_office_cross_department_recording');
        [, $unit] = $this->departmentUnit('Instructional Materials', 'IM');
        $mail = $this->psMail();

        $this->actingAs($recorder)->post(
            route('mail.department-interactions.store', $mail),
            $this->movementPayload($unit, 'ps_to_department'),
        )->assertSessionHasNoErrors();
        CorrespondenceUpdate::create([
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'note',
            'body' => 'A direct note entered by the registry.',
            'performed_by_user_id' => $recorder->id,
            'performed_by_name_snapshot' => $recorder->full_name,
        ]);

        $summary = app(ReportService::class)->build($recorder)['correspondenceSummary'];
        $this->assertSame(1, $summary['direct_actions']);
        $this->assertSame(1, $summary['reconstructed_entries']);
        $this->assertSame(1, $summary['movement_count']);
        $this->assertSame(1, $summary['currently_held']);

        $this->actingAs($recorder)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.direct_action_count', 1)
                ->where('selectedMail.reconstructed_action_count', 1)
                ->where('selectedMail.movement_count', 1)
                ->where('selectedMail.previously_handled_departments.0', 'Instructional Materials'));
    }

    public function test_standard_forwarding_and_filing_keep_current_holder_state_consistent(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create([
            'organizational_unit_id' => $this->psOffice->id,
        ]);
        [, $unit] = $this->departmentUnit('Private Schools', 'PSCH');
        $forwardedMail = $this->psMail(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $forwardedMail), [
            'target_type' => 'office',
            'organizational_unit_id' => $unit->id,
            'action_required' => false,
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();
        $this->assertSame($unit->id, $forwardedMail->fresh()->correspondence->current_holder_organizational_unit_id);

        app(MailFeatureSettings::class)->set('ps_office_cross_department_recording', true);
        $recorder = $this->psRecorder('Historical movement recorder');
        $recorder->givePermissionTo('ps_office_cross_department_recording');
        [, $historicalUnit] = $this->departmentUnit('Historical Records', 'HIST');
        $this->actingAs($recorder)->post(route('mail.department-interactions.store', $forwardedMail), [
            ...$this->movementPayload($historicalUnit, 'ps_to_department'),
            'occurred_at' => now()->subDays(3)->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors();
        $this->assertSame(
            $unit->id,
            $forwardedMail->fresh()->correspondence->current_holder_organizational_unit_id,
            'A backfilled event must not overwrite a later standard forwarding holder.',
        );
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $forwardedMail->correspondence_id,
            'entry_method' => 'ps_cross_department',
            'from_organizational_unit_id' => $this->psOffice->id,
            'to_organizational_unit_id' => $historicalUnit->id,
            'status_from' => 'incoming',
            'status_to' => 'action_required',
        ]);

        $filedMail = $this->psMail(['captured_by_user_id' => $clerk->id]);
        $this->assertSame($this->psOffice->id, $filedMail->correspondence->current_holder_organizational_unit_id);
        $this->actingAs($clerk)->post(route('mail.file', $filedMail), ['note' => 'No further action.'])
            ->assertSessionHasNoErrors();
        $this->assertNull($filedMail->fresh()->correspondence->current_holder_organizational_unit_id);
        $this->actingAs($clerk)->post(route('mail.reopen', $filedMail), ['note' => 'New response received.'])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->psOffice->id, $filedMail->fresh()->correspondence->current_holder_organizational_unit_id);
    }

    private function psRecorder(string $name = 'PS Office Registry Officer'): User
    {
        return User::factory()->role(Role::Secretary)->create([
            'full_name' => $name,
            'title' => 'Registry Officer',
            'organizational_unit_id' => $this->psOffice->id,
            'department_id' => null,
        ]);
    }

    private function psMail(array $attributes = []): MailRecord
    {
        return MailRecord::factory()->incoming()->create([
            'organizational_unit_id' => $this->psOffice->id,
            'department_id' => null,
            ...$attributes,
        ]);
    }

    /** @return array{Department, OrganizationalUnit} */
    private function departmentUnit(string $name, string $code): array
    {
        $department = Department::factory()->create(['name' => $name, 'code' => $code]);
        $unit = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'department',
            'name' => $name,
            'code' => "DEPT-{$code}",
            'active' => true,
        ]);
        $department->update(['organizational_unit_id' => $unit->id]);

        return [$department, $unit];
    }

    /** @return array<string, mixed> */
    private function movementPayload(OrganizationalUnit $unit, string $direction): array
    {
        return [
            'direction' => $direction,
            'organizational_unit_id' => $unit->id,
            'action_type' => $direction === 'ps_to_department' ? 'forwarded_for_action' : 'returned_to_ps',
            'annotation' => 'Recorded paper-based correspondence movement.',
            'occurred_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'status_after' => $direction === 'ps_to_department' ? 'action_required' : 'responded',
        ];
    }
}
