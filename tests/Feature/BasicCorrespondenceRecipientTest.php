<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BasicCorrespondenceRecipientTest extends TestCase
{
    use RefreshDatabase;

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
}
