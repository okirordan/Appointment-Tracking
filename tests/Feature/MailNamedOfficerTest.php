<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailNamedOfficerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_recorder_can_save_and_reuse_an_officer_name_without_creating_an_account(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $userCount = User::count();

        $created = $this->actingAs($clerk)->postJson(route('mail.named-officers.store'), [
            'full_name' => '  Grace   Atim  ',
        ])->assertCreated()->json('officer');

        $this->assertSame('Grace Atim', $created['full_name']);
        $this->assertSame($userCount, User::count());
        $this->actingAs($clerk)->getJson(route('mail.named-officers.index', ['q' => 'atim']))
            ->assertOk()->assertJsonPath('officers.0.id', $created['id']);

        $this->actingAs($clerk)->postJson(route('mail.named-officers.store'), [
            'full_name' => 'grace atim',
        ])->assertOk()->assertJsonPath('officer.id', $created['id']);
        $this->assertDatabaseCount('mail_named_officers', 1);
    }

    public function test_incoming_mail_can_record_a_saved_name_without_assigning_a_user(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $namedOfficerId = $this->actingAs($clerk)->postJson(route('mail.named-officers.store'), [
            'full_name' => 'Grace Atim',
        ])->assertCreated()->json('officer.id');

        $this->actingAs($clerk)->post(route('mail.incoming.store'), [
            'source_type' => 'external',
            'external_source' => 'District Education Office',
            'destination_type' => 'internal',
            'destination_directory_type' => 'named_officer',
            'recipient_named_officer_id' => $namedOfficerId,
            'recipient_name' => 'A different person',
            'subject' => 'New officer correspondence',
            'received_date' => today()->toDateString(),
            'confidentiality' => 'normal',
        ])->assertSessionHasNoErrors();

        $mail = MailRecord::firstOrFail();
        $this->assertSame('internal', $mail->destination_type);
        $this->assertSame('Grace Atim', $mail->recipient_name);
        $this->assertSame($namedOfficerId, $mail->recipient_named_officer_id);
        $this->assertNull($mail->recipient_staff_user_id);
        $this->assertDatabaseMissing('correspondence_recipients', ['correspondence_id' => $mail->correspondence_id]);
        $this->actingAs($clerk)->get(route('mail.show', $mail))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selectedMail.addressee_display', 'Grace Atim'));
    }

    public function test_saved_name_cannot_be_combined_with_a_staff_account_or_used_for_outgoing_mail(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $namedOfficerId = $this->actingAs($clerk)->postJson(route('mail.named-officers.store'), [
            'full_name' => 'Grace Atim',
        ])->assertCreated()->json('officer.id');
        $staff = User::factory()->role(Role::Officer)->create();
        $base = [
            'source_type' => 'external',
            'external_source' => 'District Education Office',
            'destination_type' => 'internal',
            'destination_directory_type' => 'named_officer',
            'recipient_named_officer_id' => $namedOfficerId,
            'subject' => 'Invalid recipient combination',
            'received_date' => today()->toDateString(),
            'confidentiality' => 'normal',
        ];

        $this->actingAs($clerk)->post(route('mail.incoming.store'), [
            ...$base,
            'recipient_staff_user_id' => $staff->id,
        ])->assertSessionHasErrors('recipient_staff_user_id');
        $this->actingAs($clerk)->post(route('mail.incoming.store'), [
            ...$base,
            'recipient_named_officer_id' => '',
        ])->assertSessionHasErrors('recipient_named_officer_id');
        $this->actingAs($clerk)->post(route('mail.outgoing.store'), $base)
            ->assertSessionHasErrors('destination_directory_type');
        $this->assertDatabaseCount('mail_records', 0);
    }

    public function test_user_without_mail_capture_access_cannot_add_names(): void
    {
        $officer = User::factory()->role(Role::Officer)->create();

        $this->actingAs($officer)->postJson(route('mail.named-officers.store'), [
            'full_name' => 'Grace Atim',
        ])->assertForbidden();
        $this->assertDatabaseCount('mail_named_officers', 0);
    }
}
