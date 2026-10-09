<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CorrespondenceUpdate;
use App\Models\AnnotationTitle;
use App\Models\CorrespondenceForward;
use App\Models\CorrespondenceRecipient;
use App\Models\MailRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BasicCorrespondenceRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_advanced_mail_annotation_records_source_and_receiving_office_for_basic_display(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $destination = AnnotationTitle::where('normalized_shorthand', 'chrm')->firstOrFail();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $actor->id,
            'recipient_annotation_title_id' => $destination->id,
        ]);
        $mail->correspondence->update(['current_holder_organizational_unit_id' => null]);

        $this->actingAs($actor)->post(route('mail.updates.store', $mail), [
            'type' => 'annotation',
            'body' => 'Please handle this carefully.',
        ])->assertSessionHasNoErrors();

        $entry = CorrespondenceUpdate::where('type', 'annotation')->firstOrFail();
        $this->assertSame($actor->officialOfficeName(), $entry->source_name_snapshot);
        $this->assertSame('C/HRM', $entry->destination_office_snapshot);
        $detail = $this->get(route('mail.show', [$mail, 'mode' => 'basic']))->assertOk()->inertiaProps('selectedMail');
        $shown = collect($detail['basic_correspondences'])->firstWhere('id', $entry->id);
        $this->assertSame('PS/ES', $shown['from_office']);
        $this->assertSame('C/HRM', $shown['destination_office']);
    }

    public function test_custom_recipient_is_linked_reused_and_shown_with_from_and_to_offices(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $actor->id]);
        $first = [
            'entry_method' => 'basic_correspondence', 'type' => 'note', 'body' => 'Did you see this?',
            'destination_key' => 'new-recipient', 'destination_office_snapshot' => '  Uganda   National ICT Association ',
            'destination_kind' => 'organization', 'recorded_date' => today()->toDateString(),
        ];
        $this->actingAs($actor)->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), $first)
            ->assertSessionHasNoErrors();
        $entry = CorrespondenceUpdate::where('body', 'Did you see this?')->firstOrFail();
        $this->assertNotNull($entry->destination_office_alias_id);
        $this->assertSame('organization', $entry->destinationAlias->kind);
        $this->assertSame('Uganda National ICT Association', $entry->destinationAlias->name);
        $this->getJson(route('mail.correspondence-offices.index', [$mail, 'q' => 'National ICT', 'linked' => 1]))
            ->assertOk()->assertJsonFragment(['key' => 'recipient:'.$entry->destination_office_alias_id]);

        $this->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            ...$first, 'body' => 'Second note', 'destination_key' => 'new-recipient',
            'destination_office_snapshot' => 'uganda national ict association',
        ])->assertSessionHasNoErrors();
        $second = CorrespondenceUpdate::where('body', 'Second note')->firstOrFail();
        $this->assertSame($entry->destination_office_alias_id, $second->destination_office_alias_id);
        $this->assertDatabaseCount('correspondence_office_aliases', 1);

        $detail = $this->get(route('mail.show', [$mail, 'mode' => 'basic']))->assertOk()->inertiaProps('selectedMail');
        $shown = collect($detail['basic_correspondences'])->firstWhere('id', $entry->id);
        $this->assertSame('Did you see this?', $shown['text']);
        $this->assertSame('PS/ES', $shown['from_office']);
        $this->assertSame('Uganda National ICT Association', $shown['destination_office']);
        $this->assertSame(today()->format('d/m/Y'), $shown['logged_date']);
    }

    public function test_failed_correspondence_does_not_leave_a_custom_recipient(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $actor->id]);
        CorrespondenceUpdate::creating(function ($entry) {
            if ($entry->entry_method === 'basic_correspondence') {
                throw ValidationException::withMessages(['body' => 'Simulated failure']);
            }
        });
        $this->actingAs($actor)->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            'entry_method' => 'basic_correspondence', 'type' => 'note', 'body' => 'Will fail',
            'destination_key' => 'new-recipient', 'destination_office_snapshot' => 'New external recipient',
            'destination_kind' => 'individual', 'recorded_date' => today()->toDateString(),
        ])->assertSessionHasErrors('body');
        $this->assertDatabaseCount('correspondence_office_aliases', 0);
    }

    public function test_incoming_correspondence_routes_to_every_selected_recipient(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $actor->id,
            'recipient_name' => 'Permanent Secretary',
        ]);
        $title = AnnotationTitle::where('normalized_shorthand', 'chrm')->firstOrFail();

        $this->actingAs($actor)->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            'entry_method' => 'basic_correspondence',
            'type' => 'note',
            'body' => 'Please review together.',
            'destination_key' => 'title:'.$title->id,
            'destination_office_snapshot' => $title->shorthand,
            'additional_destinations' => [[
                'destination_key' => 'new-recipient',
                'destination_office_snapshot' => 'District Education Office',
                'destination_kind' => 'organization',
            ]],
            'recorded_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $forward = CorrespondenceForward::firstOrFail();
        $this->assertSame(2, CorrespondenceRecipient::where('correspondence_forward_id', $forward->id)->count());
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_forward_id' => $forward->id,
            'target_type' => 'title',
            'recipient_name_snapshot' => 'C/HRM',
        ]);
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_forward_id' => $forward->id,
            'target_type' => 'external',
            'external_name' => 'District Education Office',
        ]);
        $entry = CorrespondenceUpdate::where('entry_method', 'basic_correspondence')->firstOrFail();
        $this->assertCount(2, $entry->recipient_summary);
        $detail = $this->get(route('mail.show', [$mail, 'mode' => 'basic']))->assertOk()->inertiaProps('selectedMail');
        $shown = collect($detail['basic_correspondences'])->firstWhere('id', $entry->id);
        $this->assertSame('C/HRM, District Education Office', $shown['destination_office']);
    }

    public function test_outgoing_correspondence_preserves_all_selected_recipients(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $actor->id,
            'sender_name' => 'Permanent Secretary',
        ]);
        $title = AnnotationTitle::where('normalized_shorthand', 'chrm')->firstOrFail();

        $this->actingAs($actor)->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            'entry_method' => 'basic_correspondence',
            'type' => 'note',
            'body' => 'Copies sent.',
            'destination_key' => 'title:'.$title->id,
            'destination_office_snapshot' => $title->shorthand,
            'additional_destinations' => [[
                'destination_key' => 'new-recipient',
                'destination_office_snapshot' => 'District Education Office',
                'destination_kind' => 'organization',
            ]],
            'recorded_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $entry = CorrespondenceUpdate::where('body', 'Copies sent.')->firstOrFail();
        $this->assertCount(2, $entry->recipient_summary);
        $detail = $this->get(route('mail.show', [$mail, 'mode' => 'basic']))->assertOk()->inertiaProps('selectedMail');
        $shown = collect($detail['basic_correspondences'])->firstWhere('id', $entry->id);
        $this->assertSame('C/HRM, District Education Office', $shown['destination_office']);
    }
}
