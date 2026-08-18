<?php

namespace Tests\Feature;

use App\Enums\CorrespondenceStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceRecipient;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConnectedCorrespondenceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_information_only_forward_moves_mail_out_of_active_incoming_without_creating_a_task_or_duplicate_mail(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $primary = User::factory()->role(Role::Officer)->create();
        $cc = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);
        $forwardedDate = today()->subDay()->toDateString();

        $response = $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $primary->id,
            'cc_user_ids' => [$cc->id],
            'action_required' => false,
            'priority' => 'medium',
            'forwarded_date' => $forwardedDate,
            'instructions' => 'Shared for information and awareness.',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('mail.show', $mail, absolute: false));
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('mail_records', 1);
        $this->assertSame('incoming', $mail->refresh()->direction);
        $this->assertSame(CorrespondenceStatus::Forwarded, $mail->status);
        $this->assertSame('forwarded', $mail->correspondence->current_status->value);
        $this->assertSame($forwardedDate, $mail->correspondence->forwards()->firstOrFail()->forwarded_at->toDateString());
        $this->assertDatabaseHas('correspondence_recipients', [
            'user_id' => $primary->id, 'recipient_type' => 'to', 'purpose' => 'information',
        ]);
        $this->assertDatabaseHas('correspondence_recipients', [
            'user_id' => $cc->id, 'recipient_type' => 'cc', 'purpose' => 'information',
        ]);

        $this->actingAs($clerk)->get(route('mail.incoming.index'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.incoming_total', 0)->has('mails.data', 0));
        $this->actingAs($clerk)->get(route('mail.outgoing.index'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.outgoing_total', 1)->has('mails.data', 1));
        $this->actingAs($primary)->get(route('mail.show', $mail))->assertOk();
        $this->actingAs($cc)->get(route('mail.show', $mail))->assertOk();
    }

    public function test_action_required_forward_creates_a_linked_task_but_no_duplicate_outgoing_mail(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $officer->id,
            'action_required' => true,
            'priority' => 'high',
        ])->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        $this->assertSame($task->id, $mail->refresh()->task_id);
        $this->assertDatabaseCount('mail_records', 1);
        $this->assertSame('action_required', $mail->correspondence->current_status->value);
        $this->assertDatabaseHas('correspondence_recipients', [
            'user_id' => $officer->id, 'purpose' => 'action_required', 'task_id' => $task->id, 'due_date' => null,
        ]);
    }

    public function test_information_only_forward_rejects_a_due_date(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $officer->id,
            'action_required' => false,
            'priority' => 'medium',
            'due_date' => today()->addDay()->toDateString(),
        ])->assertSessionHasErrors('due_date');

        $this->assertDatabaseCount('correspondence_forwards', 0);
    }

    public function test_forwarding_rejects_a_future_forwarded_date(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $officer->id,
            'action_required' => false,
            'priority' => 'medium',
            'forwarded_date' => today()->addDay()->toDateString(),
        ])->assertSessionHasErrors('forwarded_date');

        $this->assertDatabaseCount('correspondence_forwards', 0);
    }

    public function test_cc_recipient_can_add_a_threaded_update_with_an_attachment(): void
    {
        Storage::fake('mail');
        $clerk = User::factory()->role(Role::Clerk)->create();
        $primary = User::factory()->role(Role::Officer)->create();
        $cc = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);
        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $primary->id,
            'cc_user_ids' => [$cc->id],
            'action_required' => false,
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $this->actingAs($cc)->post(route('mail.updates.store', $mail), [
            'type' => 'note',
            'body' => 'Noted for coordination with our office.',
            'attachments' => [UploadedFile::fake()->create('coordination-note.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $update = CorrespondenceUpdate::where('type', 'note')->firstOrFail();
        $attachment = CorrespondenceAttachment::where('correspondence_update_id', $update->id)->firstOrFail();
        Storage::disk('mail')->assertExists($attachment->storage_key);
        $this->assertSame($cc->id, $update->performed_by_user_id);
    }

    public function test_correspondence_history_is_a_chronological_communication_thread_without_system_events(): void
    {
        Storage::fake('mail');
        Storage::fake('evidence');
        $department = Department::factory()->create(['name' => 'Department of Basic Education']);
        $clerk = User::factory()->role(Role::Clerk)->create([
            'full_name' => 'Gorreti Namukwaya',
            'title' => 'Registry Officer',
            'department_id' => $department->id,
        ]);
        $officer = User::factory()->role(Role::Officer)->create([
            'full_name' => 'Patrick Emmanuel Muinda',
            'title' => 'Senior Education Officer',
            'department_id' => $department->id,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'department_id' => $department->id,
            'subject' => 'School inspection response',
        ]);

        Carbon::setTestNow('2026-08-06 08:00:00');
        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $officer->id,
            'action_required' => true,
            'priority' => 'high',
            'instructions' => 'Prepare the inspection response and return it for review.',
        ])->assertSessionHasNoErrors();
        $task = Task::firstOrFail();

        Carbon::setTestNow('2026-08-06 09:00:00');
        $this->actingAs($officer)->get(route('mail.show', $mail))->assertOk();
        $this->actingAs($officer)->post(route('tasks.progress.store', $task), [
            'status' => 'in_progress',
            'progress' => 40,
            'note' => 'The draft response is ready for internal verification.',
            'evidence' => [UploadedFile::fake()->create('draft-response.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-08-06 10:00:00');
        $this->actingAs($officer)->post(route('mail.updates.store', $mail), [
            'type' => 'response',
            'body' => 'Verification is complete. The signed response is attached.',
            'attachments' => [UploadedFile::fake()->create('signed-response.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-08-06 11:00:00');
        $this->actingAs($clerk)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('selectedMail.activity_history', 3)
                ->where('selectedMail.activity_history.0.message', 'Prepare the inspection response and return it for review.')
                ->where('selectedMail.activity_history.0.author_name', 'Gorreti Namukwaya')
                ->where('selectedMail.activity_history.0.author_title', 'Registry Officer')
                ->where('selectedMail.activity_history.0.author_office', 'Department of Basic Education')
                ->where('selectedMail.activity_history.0.recorded_at_label', '06/08/2026 08:00')
                ->where('selectedMail.activity_history.1.message', 'The draft response is ready for internal verification.')
                ->where('selectedMail.activity_history.1.author_name', 'Patrick Emmanuel Muinda')
                ->where('selectedMail.activity_history.1.author_title', 'Senior Education Officer')
                ->where('selectedMail.activity_history.1.author_office', 'Department of Basic Education')
                ->where('selectedMail.activity_history.1.attachments.0.filename', 'draft-response.pdf')
                ->where('selectedMail.activity_history.2.message', 'Verification is complete. The signed response is attached.')
                ->where('selectedMail.activity_history.2.attachments.0.filename', 'signed-response.pdf'));

        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'action_type' => 'Viewed',
        ]);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'action_type' => 'Progress Updated',
            'performed_by_office_snapshot' => 'Department of Basic Education',
        ]);
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'forwarded',
        ]);
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'response',
            'performed_by_office_snapshot' => 'Department of Basic Education',
        ]);

        Carbon::setTestNow();
    }

    public function test_cc_inbox_keeps_information_only_mail_separate_from_actions(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $primary = User::factory()->role(Role::Officer)->create();
        $cc = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id, 'subject' => 'Regional briefing']);
        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $primary->id,
            'cc_user_ids' => [$cc->id],
            'action_required' => false,
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $this->actingAs($cc)->get(route('correspondence.index', ['view' => 'cc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'cc')
                ->has('items.data', 1)
                ->where('items.data.0.subject', 'Regional briefing')
                ->where('items.data.0.my_recipient_type', 'cc')
                ->where('counts.action', 0));
    }

    public function test_information_only_correspondence_can_be_sent_to_an_external_primary_recipient(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'action_required' => false,
            'priority' => 'medium',
            'external_recipients' => [[
                'name' => 'Office of the Auditor General',
                'organisation' => 'Government of Uganda',
                'recipient_type' => 'to',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_id' => $mail->refresh()->correspondence_id,
            'target_type' => 'external',
            'external_name' => 'Office of the Auditor General',
            'recipient_type' => 'to',
            'purpose' => 'information',
        ]);
    }

    public function test_action_required_correspondence_can_be_assigned_to_an_external_primary_without_a_system_account(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);
        $dueDate = today()->addDays(10)->toDateString();

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'action_required' => true,
            'priority' => 'high',
            'due_date' => $dueDate,
            'external_recipients' => [[
                'name' => 'Education Development Partner',
                'organisation' => 'Regional Programme Office',
                'recipient_type' => 'to',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertSame('action_required', $mail->refresh()->correspondence->current_status->value);
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_id' => $mail->correspondence_id,
            'target_type' => 'external',
            'external_name' => 'Education Development Partner',
            'external_organisation' => 'Regional Programme Office',
            'recipient_type' => 'to',
            'purpose' => 'action_required',
            'task_id' => null,
            'due_date' => $dueDate.' 00:00:00',
        ]);
    }

    public function test_forwarding_rejects_duplicate_external_recipients_in_one_action(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'action_required' => false,
            'priority' => 'medium',
            'external_recipients' => [
                ['name' => 'UNESCO Uganda', 'recipient_type' => 'to'],
                ['name' => ' unesco uganda ', 'recipient_type' => 'cc'],
            ],
        ])->assertSessionHasErrors('external_recipients');

        $this->assertDatabaseCount('correspondence_forwards', 0);
    }

    public function test_removing_a_cc_recipient_revokes_active_access_but_retains_history(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $primary = User::factory()->role(Role::Officer)->create();
        $cc = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);
        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $primary->id,
            'cc_user_ids' => [$cc->id],
            'action_required' => false,
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();
        $recipient = CorrespondenceRecipient::where('user_id', $cc->id)->firstOrFail();

        $this->actingAs($clerk)->delete(route('mail.recipients.destroy', [$mail, $recipient]), [
            'reason' => 'Copied to the wrong office.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('correspondence_recipients', [
            'id' => $recipient->id,
            'active' => false,
            'removed_by_user_id' => $clerk->id,
            'removal_reason' => 'Copied to the wrong office.',
        ]);
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'recipient_removed',
        ]);
        $this->actingAs($cc)->get(route('mail.show', $mail))->assertForbidden();
        $this->actingAs($primary)->get(route('mail.show', $mail))->assertOk();
    }

    public function test_authorized_user_can_print_the_complete_official_correspondence_record(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create(['full_name' => 'Registry Clerk']);
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'subject' => 'Official print verification',
        ]);
        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $officer->id,
            'action_required' => false,
            'priority' => 'medium',
            'instructions' => 'Retain this annotation in the official file.',
        ])->assertSessionHasNoErrors();

        $this->actingAs($clerk)->get(route('mail.print', $mail))
            ->assertOk()
            ->assertSee('Official Correspondence Record')
            ->assertSee('Official print verification')
            ->assertSee('Retain this annotation in the official file.')
            ->assertSee('Registry Clerk');

        $this->assertTrue(AuditLog::query()
            ->where('category', 'mail')
            ->where('target_id', $mail->id)
            ->where('action', 'like', 'Generated printable correspondence record%')
            ->exists());
    }

    public function test_attachment_replace_and_remove_preserve_every_file_version_and_audit_event(): void
    {
        Storage::fake('mail');
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);
        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $officer->id,
            'action_required' => false,
            'priority' => 'medium',
            'attachments' => [UploadedFile::fake()->create('first-version.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $original = CorrespondenceAttachment::firstOrFail();

        $this->actingAs($clerk)->post(route('correspondence.attachments.replace', $original), [
            'replacement' => UploadedFile::fake()->create('corrected-version.pdf', 22, 'application/pdf'),
            'reason' => 'Corrected the signed document.',
        ])->assertSessionHasNoErrors();

        $replacement = CorrespondenceAttachment::where('supersedes_attachment_id', $original->id)->firstOrFail();
        $this->assertSame('superseded', $original->refresh()->status);
        $this->assertSame(2, $replacement->version_number);
        $this->assertSame($original->version_group, $replacement->version_group);
        Storage::disk('mail')->assertExists($original->storage_key);
        Storage::disk('mail')->assertExists($replacement->storage_key);

        $this->actingAs($clerk)->delete(route('correspondence.attachments.destroy', $replacement), [
            'reason' => 'Document withdrawn from active use.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('removed', $replacement->refresh()->status);
        Storage::disk('mail')->assertExists($replacement->storage_key);
        $this->assertDatabaseHas('correspondence_updates', ['type' => 'attachment_replaced']);
        $this->assertDatabaseHas('correspondence_updates', ['type' => 'attachment_removed']);
    }

    public function test_participant_direct_view_does_not_expose_the_surrounding_registry(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $visible = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);
        MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);
        $this->actingAs($clerk)->post(route('mail.assign', $visible), [
            'assigned_to_user_id' => $officer->id,
            'action_required' => false,
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $this->actingAs($officer)->get(route('mail.show', $visible))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('mails.data', 1)
                ->where('mails.data.0.id', $visible->id)
                ->has('departmentOptions', 0)
                ->has('officerOptions', 0)
                ->has('financialYearOptions', 0));
    }
}
