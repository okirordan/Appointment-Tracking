<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AnnotationTitle;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\RecipientAlias;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use App\Services\Mail\MailboxScope;
use App\Services\SearchService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BasicMailImprovementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_correspondence_forward_and_search_preserve_identity_and_history(): void
    {
        Storage::fake('mail');
        $actor = User::factory()->role(Role::Ps)->create();
        $department = Department::factory()->create(['code' => 'EDTEST', 'name' => 'Education testing office']);
        $ps = AnnotationTitle::where('normalized_shorthand', 'pses')->firstOrFail();
        $destination = AnnotationTitle::create(['shorthand' => 'C/TEST', 'full_title' => 'Commissioner Testing', 'active' => true]);
        $this->actingAs($actor)->getJson(route('mail.directory', ['q' => 'Education testing']))
            ->assertOk()->assertJsonFragment(['key' => 'department:'.$department->id, 'value' => 'EDTEST']);
        $this->getJson(route('mail.directory', ['q' => 'C/TEST']))
            ->assertOk()->assertJsonFragment(['key' => 'title:'.$destination->id, 'value' => 'C/TEST']);
        $this->post(route('mail.incoming.store', ['mode' => 'basic']), [
            'sender_name' => 'EDTEST', 'recipient_name' => 'PS/ES', 'subject' => 'Equipment registry check',
            'basic_source_key' => 'department:'.$department->id, 'basic_recipient_key' => 'title:'.$ps->id,
            'source_type' => 'external', 'destination_type' => 'external',
            'received_date' => today()->toDateString(), 'priority' => 'medium', 'confidentiality' => 'normal',
            'details' => 'Original content preserved', 'attachments' => [UploadedFile::fake()->create('letter.pdf', 5, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $mail = MailRecord::where('subject', 'Equipment registry check')->firstOrFail();
        $reference = $mail->register_number;
        $this->assertSame($department->id, $mail->source_department_id);
        $this->assertSame($ps->id, $mail->recipient_annotation_title_id);
        $scope = app(MailboxScope::class);
        $this->assertTrue($scope->incoming(MailRecord::query(), $actor)->whereKey($mail)->exists());
        $attachmentCount = $mail->attachments()->count();
        $this->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            'entry_method' => 'basic_correspondence', 'type' => 'note', 'body' => 'Specialized zebracorrespondence details',
            'destination_key' => 'title:'.$destination->id, 'destination_office_snapshot' => 'C/TEST',
            'recorded_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($scope->incoming(MailRecord::query(), $actor)->whereKey($mail)->exists());
        $this->assertTrue($scope->outgoing(MailRecord::query(), $actor)->whereKey($mail)->exists());
        $this->assertDatabaseCount('mail_records', 1);
        $this->assertDatabaseCount('correspondence_forwards', 1);
        $this->assertSame($reference, $mail->fresh()->register_number);
        $this->assertSame('Original content preserved', $mail->fresh()->details);
        $this->assertSame($attachmentCount, $mail->attachments()->count());
        $entry = CorrespondenceUpdate::where('entry_method', 'basic_correspondence')->sole();
        $this->assertNotNull($entry->correspondence_forward_id);
        $this->assertSame($destination->id, $entry->destination_annotation_title_id);
        $outgoing = $this->get(route('mail.outgoing.index', ['mode' => 'basic']))->assertOk()->inertiaProps('mails.data');
        $movement = collect($outgoing)->firstWhere('id', $mail->id);
        $this->assertSame('Education testing office', $movement['provenance']['original_source']);
        $this->assertNotSame($movement['provenance']['original_source'], $movement['provenance']['latest_forward']['from']);
        $this->assertNotEmpty($movement['provenance']['latest_forward']['to']);
        $this->assertSame(['C/TEST'], $movement['provenance']['latest_forward']['to_display']);
        $this->assertSame('outgoing', $movement['mailbox_direction']);
        $destination->update(['shorthand' => 'C/RENAMED']);
        $detail = $this->get(route('mail.show', [$mail, 'mode' => 'basic']))->assertOk()->inertiaProps('selectedMail');
        $this->assertSame('EDTEST', $detail['sender_display']);
        $this->assertSame('C/RENAMED', $detail['basic_correspondences'][0]['destination_office']);
        foreach (['zebracorrespondence', 'C/RENAMED', 'EDTEST'] as $term) {
            $this->get('/home?mode=basic&type=all&q='.urlencode($term))->assertOk()
                ->assertInertia(fn ($page) => $page->component('mail/index')->where('results.mails.0.id', $mail->id));
        }
        // Later correspondence on an already sent record does not duplicate the movement.
        $this->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            'entry_method' => 'basic_correspondence', 'type' => 'note', 'body' => 'Additional confirmation',
            'destination_key' => 'title:'.$destination->id, 'destination_office_snapshot' => 'C/RENAMED',
            'recorded_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('correspondence_forwards', 1);
        $this->assertSame(2, CorrespondenceUpdate::where('entry_method', 'basic_correspondence')->count());
    }

    public function test_failed_forward_rolls_back_correspondence_and_movement_and_full_notes_do_not_forward(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $actor->id, 'recipient_name' => 'Permanent Secretary']);
        $this->actingAs($actor)->post(route('mail.updates.store', [$mail, 'mode' => 'full']), ['type' => 'note', 'body' => 'Full Mode note'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('correspondence_forwards', 0);
        // Force an actual failure after the forwarding rows were inserted.
        CorrespondenceUpdate::creating(function ($entry) {
            if ($entry->entry_method === 'basic_correspondence') {
                throw ValidationException::withMessages(['body' => 'Simulated save failure']);
            }
        });
        $this->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            'entry_method' => 'basic_correspondence', 'type' => 'note', 'body' => 'Must roll back',
            'destination_office_snapshot' => 'External test office', 'recorded_date' => today()->toDateString(),
        ])->assertSessionHasErrors('body');
        $this->assertTrue(app(MailboxScope::class)->incoming(MailRecord::query(), $actor)->whereKey($mail)->exists());
        $this->assertDatabaseCount('correspondence_forwards', 0);
        $this->assertDatabaseMissing('correspondence_updates', ['body' => 'Must roll back']);
        $this->assertDatabaseHas('correspondence_updates', ['body' => 'Full Mode note']);
    }

    public function test_search_never_exposes_another_offices_restricted_correspondence(): void
    {
        $secretary = User::factory()->role(Role::Secretary)->create();
        $owner = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $owner->id, 'confidentiality' => 'restricted']);
        CorrespondenceUpdate::create(['correspondence_id' => $mail->correspondence_id, 'type' => 'note', 'body' => 'Hidden zebra details', 'performed_by_user_id' => $owner->id, 'performed_by_name_snapshot' => $owner->full_name]);
        $results = app(SearchService::class)->search($secretary, 'Hidden zebra', 'all', false, 1, 20, true);
        $this->assertSame([], $results['mails']);
    }

    public function test_department_secretary_routes_basic_correspondence_to_a_linked_department(): void
    {
        $this->seed(RoleSeeder::class);
        $home = Department::factory()->create();
        $destination = Department::factory()->create(['code' => 'DST', 'name' => 'Destination department']);
        $office = OrganizationalUnit::create(['type' => 'department', 'department_id' => $home->id, 'name' => 'Registry office', 'code' => 'REG', 'active' => true]);
        $head = User::factory()->role(Role::Commissioner)->create(['department_id' => $home->id]);
        $secretary = User::factory()->role(Role::Secretary)->create(['department_id' => $home->id]);
        $receiver = User::factory()->role(Role::Commissioner)->create(['department_id' => $destination->id]);
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id, 'supervisor_user_id' => $head->id,
            'organizational_unit_id' => $office->id, 'official_job_title' => 'Secretary',
            'starts_at' => now()->subMinute(), 'delegated_actions_permitted' => true,
            'delegated_permissions' => ['mail.manage', 'mail.assign'], 'active' => true,
        ]);
        $this->actingAs($secretary)->post(route('mail.incoming.store', ['mode' => 'basic']), [
            'sender_name' => 'Regional school', 'recipient_name' => $head->full_name,
            'basic_source_key' => '', 'basic_recipient_key' => 'user:'.$head->id,
            'subject' => 'Department registry workflow', 'received_date' => today()->toDateString(),
            'priority' => 'medium', 'confidentiality' => 'normal',
        ])->assertSessionHasNoErrors();
        $mail = MailRecord::where('subject', 'Department registry workflow')->firstOrFail();
        $this->assertSame($head->id, $mail->recipient_staff_user_id);
        $this->assertTrue(app(MailboxScope::class)->incoming(MailRecord::query(), $secretary)->whereKey($mail)->exists());
        $this->post(route('mail.updates.store', [$mail, 'mode' => 'basic']), [
            'entry_method' => 'basic_correspondence', 'type' => 'note', 'body' => 'Department response required.',
            'destination_key' => 'department:'.$destination->id, 'destination_office_snapshot' => 'DST',
            'recorded_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertFalse(app(MailboxScope::class)->incoming(MailRecord::query(), $secretary)->whereKey($mail)->exists());
        $this->assertTrue(app(MailboxScope::class)->outgoing(MailRecord::query(), $secretary)->whereKey($mail)->exists());
        $this->assertTrue(app(MailboxScope::class)->incoming(MailRecord::query(), $receiver)->whereKey($mail)->exists());
        $this->actingAs($receiver)->get(route('mail.show', [$mail, 'mode' => 'basic']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedMail.basic_correspondences.0.destination_office', 'DST'));
        $this->assertDatabaseHas('correspondence_recipients', ['correspondence_id' => $mail->correspondence_id, 'target_type' => 'department', 'department_id' => $destination->id]);
    }

    public function test_global_staff_search_and_directory_find_officer_shorthand(): void
    {
        $actor = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create(['full_name' => 'Shorthand Officer']);
        RecipientAlias::create(['alias' => 'SH/TEST', 'target_type' => User::class, 'target_id' => $officer->id, 'active' => true]);
        $this->actingAs($actor)->getJson(route('mail.directory', ['q' => 'SH/TEST']))->assertOk()
            ->assertJsonFragment(['key' => 'user:'.$officer->id, 'value' => 'SH/TEST']);
        $this->get('/home?mode=basic&type=staff&q=SH%2FTEST')->assertOk()
            ->assertInertia(fn ($page) => $page->component('mail/index')->where('results.officers.0.id', $officer->id)->where('results.officers.0.title', 'SH/TEST'));
    }
}
