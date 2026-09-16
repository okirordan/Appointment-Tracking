<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CorrespondenceRecipient;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\Mail\CorrespondenceForwardingService;
use App\Services\Mail\MailAccessScope;
use App\Services\Mail\MailRecordPresenter;
use App\Services\Mail\MailRecordService;
use App\Services\Tasks\AssignmentWorkflowService;
use App\Services\Tasks\TaskPresenter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 10)->setTime(8, 0));
    }

    public function test_external_origin_and_original_receiving_office_remain_distinct_after_multiple_forwards(): void
    {
        [$mail, $ps, $leitOfficer, $leitUnit] = $this->scenario();
        $service = app(CorrespondenceForwardingService::class);
        $this->travel(1)->hours();
        $service->forward($ps, $mail, [
            'target_type' => 'department', 'target_department_id' => $leitOfficer->department_id,
            'action_required' => false, 'priority' => 'medium',
            'instructions' => 'LEIT should review the proposal.',
        ]);

        $this->assertTrue($leitOfficer->can('view', $mail->fresh()));
        $first = app(MailRecordPresenter::class)->detail($mail->fresh(), viewer: $leitOfficer);
        $this->assertSame('Ministry of Finance', $first['provenance']['original_source']);
        $this->assertSame('Permanent Secretary, Ministry of Education and Sports', $first['provenance']['original_addressee']);
        $this->assertSame('Office of the Permanent Secretary', $first['provenance']['received_by']);
        $this->assertContains('Office of the Permanent Secretary', $first['provenance']['received_through']);
        $this->assertContains('LEIT Department', $first['provenance']['current_locations']);
        $this->assertSame('Office of the Permanent Secretary', $first['provenance']['latest_forward']['from']);
        $this->assertSame('PS forwarding officer', $first['provenance']['latest_forward']['by']);
        $this->assertContains('LEIT Department', $first['provenance']['latest_forward']['to']);

        $nextUnit = OrganizationalUnit::create(['type' => 'office', 'name' => 'ICT Coordination Office', 'code' => 'ICT-PROVENANCE', 'active' => true]);
        $this->travel(1)->hours();
        $service->forward($leitOfficer, $mail->fresh(), [
            'target_type' => 'office', 'organizational_unit_id' => $nextUnit->id,
            'action_required' => false, 'priority' => 'medium',
            'instructions' => 'Please provide a technical assessment.',
        ]);

        $second = app(MailRecordPresenter::class)->detail($mail->fresh());
        $this->assertSame('Ministry of Finance', $mail->fresh()->sender_name);
        $this->assertSame('Ministry of Finance', $mail->fresh()->sender_organisation);
        $this->assertSame('Permanent Secretary, Ministry of Education and Sports', $mail->fresh()->recipient_name);
        $this->assertSame($first['provenance']['original_source'], $second['provenance']['original_source']);
        $this->assertSame($first['provenance']['original_addressee'], $second['provenance']['original_addressee']);
        $this->assertContains('LEIT Department', $second['provenance']['received_through']);
        $this->assertSame('LEIT Department', $second['provenance']['latest_forward']['from']);
        $this->assertSame('LEIT forwarding officer', $second['provenance']['latest_forward']['by']);
        $this->assertContains('ICT Coordination Office', $second['provenance']['current_locations']);
        $timeline = collect($second['movement_timeline']);
        $this->assertSame($timeline->pluck('at')->sort()->values()->all(), $timeline->pluck('at')->all());
        $this->assertCount(2, $timeline->where('type', 'forwarded'));
        $this->assertTrue($timeline->contains(fn ($event) => $event['to'] === 'Office of the Permanent Secretary'));
        $this->assertTrue($timeline->contains(fn ($event) => $event['instructions'] === 'LEIT should review the proposal.'));

        $row = app(MailRecordPresenter::class)->row($mail->fresh(), 'incoming');
        $this->assertSame('Ministry of Finance', $row['provenance']['original_source']);
        $this->assertContains('Office of the Permanent Secretary', $row['provenance']['received_through']);
    }

    public function test_assignment_view_exposes_the_same_origin_and_forwarding_route(): void
    {
        [$mail, $ps, $officer] = $this->scenario();
        $result = app(CorrespondenceForwardingService::class)->forward($ps, $mail, [
            'assigned_to_user_id' => $officer->id, 'action_required' => true,
            'priority' => 'medium', 'instructions' => 'Prepare a response.',
        ]);
        $payload = app(TaskPresenter::class)->detail($result['task']->fresh(), $officer);
        $this->assertNotNull($payload['mail_origin']);
        $this->assertSame('Ministry of Finance', $payload['mail_origin']['provenance']['original_source']);
        $this->assertSame('Permanent Secretary, Ministry of Education and Sports', $payload['mail_origin']['provenance']['original_addressee']);
        $this->assertContains('Office of the Permanent Secretary', $payload['mail_origin']['provenance']['received_through']);
        $this->assertSame('PS forwarding officer', $payload['mail_origin']['provenance']['latest_forward']['by']);
    }

    public function test_legacy_outgoing_forward_resolves_the_external_origin_from_its_source_record(): void
    {
        [$mail, $ps, $officer] = $this->scenario();
        app(MailRecordService::class)->assign($ps, $mail, [
            'assigned_to_user_id' => $officer->id, 'priority' => 'medium',
            'instructions' => 'Review the funding proposal.',
        ]);
        $forwarded = MailRecord::query()->where('source_mail_record_id', $mail->id)->firstOrFail();
        $payload = app(MailRecordPresenter::class)->detail($forwarded);
        $this->assertSame('Ministry of Finance', $payload['provenance']['original_source']);
        $this->assertSame('Permanent Secretary, Ministry of Education and Sports', $payload['provenance']['original_addressee']);
        $this->assertSame('Office of the Permanent Secretary', $payload['provenance']['received_by']);
        $this->assertSame('Ministry of Finance', $mail->fresh()->sender_name);
    }

    public function test_information_copy_is_not_reported_as_a_current_handling_location(): void
    {
        [$mail, $ps, , $leitUnit] = $this->scenario();
        $copyOffice = OrganizationalUnit::create(['type' => 'office', 'name' => 'Information Copy Office', 'code' => 'COPY-PROVENANCE', 'active' => true]);
        $copyUser = User::factory()->role(Role::Officer)->create(['organizational_unit_id' => $copyOffice->id]);
        app(CorrespondenceForwardingService::class)->forward($ps, $mail, [
            'target_type' => 'office', 'organizational_unit_id' => $leitUnit->id,
            'cc_user_ids' => [$copyUser->id], 'action_required' => false, 'priority' => 'medium',
        ]);

        $detail = app(MailRecordPresenter::class)->detail($mail->fresh());
        $this->assertSame(['LEIT Department'], $detail['provenance']['current_locations']);
        $this->assertCount(1, collect($detail['movement_timeline'])->where('type', 'copied'));
    }

    public function test_forwarding_actor_and_office_snapshots_survive_directory_renames(): void
    {
        [$mail, $ps, , $leitUnit] = $this->scenario();
        app(CorrespondenceForwardingService::class)->forward($ps, $mail, [
            'target_type' => 'office', 'organizational_unit_id' => $leitUnit->id,
            'action_required' => false, 'priority' => 'medium',
        ]);
        $ps->update(['full_name' => 'Updated PS user name']);
        $ps->organizationalUnit->update(['name' => 'Renamed PS Office']);
        $leitUnit->update(['name' => 'Renamed LEIT Department']);

        $detail = app(MailRecordPresenter::class)->detail($mail->fresh());
        $forward = $detail['provenance']['latest_forward'];
        $this->assertSame('PS forwarding officer', $forward['by']);
        $this->assertSame('Office of the Permanent Secretary', $forward['from']);
        $this->assertSame(['LEIT Department'], $forward['to']);
        $event = collect($detail['movement_timeline'])->firstWhere('type', 'forwarded');
        $this->assertSame('PS forwarding officer', $event['by']);
        $this->assertSame('Office of the Permanent Secretary', $event['from']);
        $this->assertSame('LEIT Department', $event['to']);
    }

    public function test_historical_participant_does_not_see_unrelated_forward_routes_or_legacy_instructions(): void
    {
        [$mail, $ps, $officer, $leitUnit] = $this->scenario();
        $other = OrganizationalUnit::create(['type' => 'office', 'name' => 'Unrelated Office', 'code' => 'OTHER-PROVENANCE', 'active' => true]);
        app(CorrespondenceForwardingService::class)->forward($ps, $mail, [
            'target_type' => 'office', 'organizational_unit_id' => $other->id,
            'action_required' => false, 'priority' => 'medium', 'instructions' => 'Restricted normal forwarding instruction.',
        ]);
        $mail->refresh();
        CorrespondenceUpdate::create([
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'returned_to_ps', 'entry_method' => 'ps_cross_department',
            'from_organizational_unit_id' => $leitUnit->id,
            'to_organizational_unit_id' => $ps->organizational_unit_id,
            'performed_by_user_id' => $ps->id, 'performed_by_name_snapshot' => $ps->full_name,
            'body' => 'LEIT historical participation.',
        ]);
        MailRecord::factory()->outgoing()->create([
            'source_mail_record_id' => $mail->id, 'sender_name' => 'Unrelated Office',
            'recipient_name' => 'Restricted legacy recipient', 'details' => 'Restricted legacy instruction.',
            'captured_by_user_id' => $ps->id,
        ]);
        $this->assertFalse(app(MailAccessScope::class)->allowsWithoutHistoricalInteractions($officer, $mail->fresh()));
        $this->assertTrue($officer->can('view', $mail->fresh()));

        $detail = app(MailRecordPresenter::class)->detail($mail->fresh(), viewer: $officer);
        $timeline = collect($detail['movement_timeline']);
        $this->assertTrue($timeline->contains('instructions', 'LEIT historical participation.'));
        $this->assertFalse($timeline->contains('instructions', 'Restricted normal forwarding instruction.'));
        $this->assertFalse($timeline->contains('instructions', 'Restricted legacy instruction.'));
        $this->assertStringNotContainsString('Restricted legacy recipient', json_encode($detail['provenance']));
        $this->assertStringNotContainsString('Unrelated Office', json_encode($detail['provenance']));
    }

    public function test_delegating_linked_assignment_updates_current_office_handler_and_movement_history(): void
    {
        [$mail, $ps, $commissioner] = $this->scenario();
        $technicalOffice = OrganizationalUnit::create(['type' => 'office', 'name' => 'Technical Review Office', 'code' => 'TECH-PROVENANCE', 'active' => true]);
        $technicalOfficer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Technical review officer', 'title' => 'ICT Officer',
            'department_id' => $commissioner->department_id, 'organizational_unit_id' => $technicalOffice->id,
        ]);
        $result = app(CorrespondenceForwardingService::class)->forward($ps, $mail, [
            'assigned_to_user_id' => $commissioner->id, 'action_required' => true, 'priority' => 'medium',
        ]);
        $this->travel(1)->hours();
        app(AssignmentWorkflowService::class)->delegate($commissioner, $result['task']->fresh(), $technicalOfficer, [
            'instructions' => 'Assess the technical requirements.',
        ]);

        $detail = app(MailRecordPresenter::class)->detail($mail->fresh(), viewer: $ps);
        $this->assertSame(['Technical Review Office'], $detail['provenance']['current_locations']);
        $this->assertContains('Technical review officer', $detail['provenance']['current_handlers']);
        $assigned = collect($detail['movement_timeline'])->where('type', 'assigned');
        $delegated = $assigned->firstWhere('to', 'Technical review officer');
        $this->assertNotNull($delegated);
        $this->assertSame('Assess the technical requirements.', $delegated['instructions']);
        $this->assertSame('LEIT forwarding officer', $delegated['by']);
        $this->assertSame('Ministry of Finance', $detail['provenance']['original_source']);
    }

    public function test_successive_forwards_at_the_same_timestamp_supersede_the_previous_location(): void
    {
        [$mail, $ps, $commissioner, $leitUnit] = $this->scenario();
        $nextOffice = OrganizationalUnit::create(['type' => 'office', 'name' => 'Next Handling Office', 'code' => 'NEXT-PROVENANCE', 'active' => true]);
        $forwarding = app(CorrespondenceForwardingService::class);
        $forwarding->forward($ps, $mail, [
            'target_type' => 'office', 'organizational_unit_id' => $leitUnit->id,
            'action_required' => false, 'priority' => 'medium',
        ]);
        // A directory rename must not sever the identity of the same office.
        $leitUnit->update(['name' => 'Renamed LEIT Office']);
        $forwarding->forward($commissioner->fresh(), $mail->fresh(), [
            'target_type' => 'office', 'organizational_unit_id' => $nextOffice->id,
            'action_required' => false, 'priority' => 'medium',
        ]);

        $detail = app(MailRecordPresenter::class)->detail($mail->fresh());
        $this->assertSame(['Next Handling Office'], $detail['provenance']['current_locations']);
        $this->assertSame(['Next Handling Office'], $detail['provenance']['latest_forward']['to']);
        $this->assertCount(2, collect($detail['movement_timeline'])->where('type', 'forwarded'));
    }

    public function test_closed_or_withdrawn_correspondence_has_no_current_handling_location(): void
    {
        [$mail, $ps, , $leitUnit] = $this->scenario();
        app(CorrespondenceForwardingService::class)->forward($ps, $mail, [
            'target_type' => 'office', 'organizational_unit_id' => $leitUnit->id,
            'action_required' => false, 'priority' => 'medium',
        ]);
        foreach (['closed', 'withdrawn'] as $status) {
            $mail->fresh()->correspondence->update(['current_status' => $status]);
            $detail = app(MailRecordPresenter::class)->detail($mail->fresh());
            $this->assertSame([], $detail['provenance']['current_locations'], $status);
            $this->assertSame([], $detail['provenance']['current_handlers'], $status);
            $this->assertNotEmpty($detail['movement_timeline']);
        }
    }

    public function test_print_marks_each_action_and_keeps_origin_separate_from_forwarding(): void
    {
        [$mail, $ps, $officer] = $this->scenario();
        $this->travel(1)->hours();
        $result = app(CorrespondenceForwardingService::class)->forward($ps, $mail, [
            'assigned_to_user_id' => $officer->id, 'action_required' => true,
            'priority' => 'medium', 'instructions' => 'Prepare the technical response.',
        ]);
        $this->travel(1)->hours();
        $note = CorrespondenceUpdate::create([
            'correspondence_id' => $mail->correspondence_id, 'type' => 'annotation',
            'body' => 'Check the supporting figures <script>alert(1)</script>',
            'performed_by_user_id' => $ps->id, 'performed_by_name_snapshot' => $ps->full_name,
            'status_from' => 'forwarded', 'status_to' => 'action_required',
            'occurred_at' => now()->subMinutes(30), 'recorded_at' => now(),
        ]);
        $this->travel(1)->hours();
        $workflow = app(AssignmentWorkflowService::class);
        $submission = $workflow->submit($officer, $result['task']->fresh(), 'Technical response submitted.');
        $workflow->review($ps, $submission, ['decision' => 'approve', 'comments' => 'Technical response approved.']);
        $response = $this->actingAs($ps)->get(route('mail.print', $mail))->assertOk();
        $response->assertSee('Original source')->assertSee('Ministry of Finance')
            ->assertSee('Originally addressed to')->assertSee('Office of the Permanent Secretary')
            ->assertSee('Mail flow and action history')->assertSee('Action by:')
            ->assertSee('Forwarded by:')->assertSee('Assigned by:')->assertSee('Delivery recorded by system')
            ->assertSee('Responsibility assigned')->assertSee('Submitted for review')->assertSee('Review approved')
            ->assertSee('Officer title at assignment:')->assertSee('Commissioner LEIT')
            ->assertSee('Recorded in ATS:')->assertSee('Action required')
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-event-id="update-'.$note->id.'"'));
        $this->assertLessThan(strpos($response->getContent(), 'Review approved'), strpos($response->getContent(), 'Submitted for review'));
    }

    public function test_original_addressees_are_not_labelled_as_forwarded_in_print(): void
    {
        [$mail, $ps, $officer] = $this->scenario();
        CorrespondenceRecipient::create([
            'correspondence_id' => $mail->correspondence_id, 'recipient_type' => 'to',
            'purpose' => 'action_required', 'target_type' => 'individual', 'user_id' => $officer->id,
            'recipient_name_snapshot' => $officer->full_name, 'recipient_title_snapshot' => $officer->title,
            'added_by_user_id' => $ps->id, 'added_at' => now(), 'active' => true,
        ]);
        $record = app(MailRecordPresenter::class)->detail($mail->fresh(), viewer: $ps);
        $this->assertCount(1, collect($record['movement_timeline'])->where('type', 'original_addressee'));
        $this->assertCount(0, collect($record['movement_timeline'])->where('type', 'forwarded'));
        $this->actingAs($ps)->get(route('mail.print', $mail))->assertOk()->assertSee('Original addressee recorded');
    }

    private function scenario(): array
    {
        $psOffice = OrganizationalUnit::where('code', 'OPS')->firstOrFail();
        $psOffice->update(['name' => 'Office of the Permanent Secretary']);
        $department = Department::factory()->create(['name' => 'LEIT Department', 'code' => 'LEIT-PROVENANCE']);
        $leitUnit = OrganizationalUnit::create([
            'type' => 'department', 'name' => 'LEIT Department', 'code' => 'LEIT-PROVENANCE',
            'department_id' => $department->id, 'active' => true,
        ]);
        $department->update(['organizational_unit_id' => $leitUnit->id]);
        $ps = User::factory()->role(Role::Ps)->create([
            'full_name' => 'PS forwarding officer', 'title' => 'Permanent Secretary',
            'organizational_unit_id' => $psOffice->id,
        ]);
        $officer = User::factory()->role(Role::Commissioner)->create([
            'full_name' => 'LEIT forwarding officer', 'title' => 'Commissioner LEIT',
            'department_id' => $department->id, 'organizational_unit_id' => $leitUnit->id,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'sender_name' => 'Ministry of Finance', 'sender_organisation' => 'Ministry of Finance',
            'source_type' => 'external', 'recipient_name' => 'Permanent Secretary, Ministry of Education and Sports',
            'captured_by_user_id' => $ps->id, 'office_supervisor_user_id' => $ps->id,
            'organizational_unit_id' => $psOffice->id,
        ]);

        return [$mail, $ps, $officer, $leitUnit];
    }
}
