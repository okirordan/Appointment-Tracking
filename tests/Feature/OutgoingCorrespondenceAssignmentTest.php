<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AssignmentParticipant;
use App\Models\AuditLog;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceRecipient;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\User;
use App\Services\Mail\MailFeatureSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OutgoingCorrespondenceAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        Storage::fake('mail');
        app(MailFeatureSettings::class)->set('priority', true);
    }

    public function test_outgoing_correspondence_can_create_an_optional_follow_up_assignment_when_recorded(): void
    {
        $ps = User::factory()->role(Role::Ps)->create(['full_name' => 'Permanent Secretary']);
        $responsible = User::factory()->role(Role::Officer)->create(['full_name' => 'Responsible Officer']);
        $cc = User::factory()->role(Role::Officer)->create(['full_name' => 'Information Officer']);

        $this->actingAs($ps)->post(route('mail.outgoing.store'), [
            'sender_name' => 'Office of the Permanent Secretary',
            'recipient_name' => 'District Education Officer',
            'subject' => 'Follow up the school inspection response',
            'details' => 'A response was sent and implementation now needs to be monitored.',
            'sent_date' => today()->toDateString(),
            'confidentiality' => 'normal',
            'priority' => 'high',
            'status' => 'dispatched',
            'requires_follow_up' => true,
            'assigned_to_user_id' => $responsible->id,
            'cc_user_ids' => [$cc->id],
            'instructions' => 'Confirm implementation and report any unresolved issues.',
            'due_date' => today()->addWeek()->toDateString(),
            'attachments' => [UploadedFile::fake()->create('outgoing-letter.pdf', 25, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $mail = MailRecord::firstOrFail();
        $task = Task::firstOrFail();

        $this->assertSame($task->id, $mail->task_id);
        $this->assertSame($responsible->id, $task->responsible_user_id);
        $this->assertSame('high', $task->priority->value);
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_id' => $mail->correspondence_id,
            'user_id' => $responsible->id,
            'recipient_type' => 'to',
            'purpose' => 'action_required',
            'task_id' => $task->id,
        ]);
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_id' => $mail->correspondence_id,
            'user_id' => $cc->id,
            'recipient_type' => 'cc',
            'purpose' => 'information',
            'task_id' => null,
        ]);
        $this->assertFalse(AssignmentParticipant::query()
            ->where('task_id', $task->id)
            ->where('user_id', $cc->id)
            ->where('participant_type', 'assignee')
            ->exists());
        $this->assertDatabaseHas('notifications', ['user_id' => $responsible->id, 'type' => 'new_assignment']);
        $this->assertDatabaseHas('notifications', ['user_id' => $cc->id, 'type' => 'correspondence_cc']);

        $this->actingAs($cc)->get(route('correspondence.index', ['view' => 'cc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'cc')
                ->where('items.data.0.id', $mail->id)
                ->where('counts.action', 0));
        $this->actingAs($cc)->post(route('tasks.progress.store', $task), [
            'status' => 'in_progress',
            'progress' => 10,
            'note' => 'A CC officer must not be able to act on this task.',
        ])->assertForbidden();

        $this->actingAs($responsible)->get(route('mail.show', $mail))->assertOk();
        $this->assertDatabaseHas('assignment_views', ['task_id' => $task->id, 'user_id' => $responsible->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $ps->id, 'type' => 'assignment_viewed']);
        $this->assertTrue(AuditLog::query()
            ->where('target_type', 'MailRecord')
            ->where('target_id', $mail->id)
            ->where('action', 'like', 'Viewed correspondence%')
            ->exists());
    }

    public function test_outgoing_correspondence_without_follow_up_remains_an_ordinary_unassigned_record(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();

        $this->actingAs($ps)->post(route('mail.outgoing.store'), [
            'sender_name' => 'Office of the Permanent Secretary',
            'recipient_name' => 'Public Service Commission',
            'subject' => 'Acknowledgement of the quarterly report',
            'sent_date' => today()->toDateString(),
            'confidentiality' => 'normal',
            'priority' => 'medium',
            'status' => 'dispatched',
            'requires_follow_up' => false,
        ])->assertSessionHasNoErrors();

        $mail = MailRecord::firstOrFail();
        $this->assertNull($mail->task_id);
        $this->assertSame(0, Task::count());
        $this->assertSame(0, CorrespondenceRecipient::count());
    }

    public function test_existing_unassigned_outgoing_correspondence_can_be_assigned_once_without_changing_the_original_record(): void
    {
        $ps = User::factory()->role(Role::Ps)->create(['full_name' => 'Permanent Secretary']);
        $responsible = User::factory()->role(Role::Officer)->create(['full_name' => 'Follow-up Officer']);
        $cc = User::factory()->role(Role::Officer)->create(['full_name' => 'Copied Officer']);
        $mail = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $ps->id,
            'sender_name' => 'Permanent Secretary',
            'recipient_name' => 'Chief Administrative Officer',
            'subject' => 'Deployment of newly appointed teachers',
            'details' => 'The signed outgoing content must remain immutable when a task is linked later.',
            'priority' => 'medium',
        ]);
        $immutableColumns = ['sender_name', 'recipient_name', 'subject', 'details', 'sent_date', 'priority'];
        $original = collect($immutableColumns)->mapWithKeys(fn (string $column) => [
            $column => $mail->getRawOriginal($column),
        ])->all();

        $this->actingAs($ps)->post(route('mail.assign-outgoing', $mail), [
            'assigned_to_user_id' => $responsible->id,
            'cc_user_ids' => [$cc->id],
            'instructions' => 'Track deployment acknowledgements from every district.',
            'due_date' => today()->addDays(10)->toDateString(),
            'priority' => 'urgent',
            'attachments' => [UploadedFile::fake()->create('deployment-checklist.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $mail->refresh();
        $task = Task::firstOrFail();
        $this->assertSame($task->id, $mail->task_id);
        foreach ($original as $column => $value) {
            $this->assertSame($value, $mail->getRawOriginal($column));
        }
        $this->assertSame(0, $mail->attachments()->count());
        $supporting = CorrespondenceAttachment::firstOrFail();
        $this->assertSame('deployment-checklist.pdf', $supporting->original_filename);
        Storage::disk('mail')->assertExists($supporting->storage_key);
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $mail->correspondence_id,
            'task_id' => $task->id,
            'type' => 'assigned',
        ]);

        $this->actingAs($ps)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.can_assign_outgoing', false)
                ->where('selectedMail.assignment.reference', $task->reference)
                ->where('selectedMail.assignment.priority', 'Urgent'));

        $this->actingAs($ps)->post(route('mail.assign-outgoing', $mail), [
            'assigned_to_user_id' => $responsible->id,
            'instructions' => 'This duplicate must never be created.',
            'priority' => 'medium',
        ])->assertForbidden();
        $this->assertSame(1, Task::count());
    }

    public function test_withdrawn_outgoing_mail_offers_reassignment_and_preserves_the_previous_assignment_trail(): void
    {
        $ps = User::factory()->role(Role::Ps)->create(['full_name' => 'Permanent Secretary']);
        $originalOfficer = User::factory()->role(Role::Officer)->create(['full_name' => 'Original Officer']);
        $replacementOfficer = User::factory()->role(Role::Officer)->create(['full_name' => 'Replacement Officer']);
        $mail = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $ps->id,
            'subject' => 'Follow up the implementation response',
            'sent_date' => today()->toDateString(),
            'status' => 'dispatched',
        ]);

        $this->actingAs($ps)->post(route('mail.assign-outgoing', $mail), [
            'assigned_to_user_id' => $originalOfficer->id,
            'instructions' => 'Track the response and report back.',
            'priority' => 'high',
        ])->assertSessionHasNoErrors();
        $withdrawnTask = Task::firstOrFail();

        $this->actingAs($ps)->post(route('tasks.workflow.unassign', $withdrawnTask), [
            'user_ids' => [$originalOfficer->id],
            'reason' => 'The follow-up must move to another officer.',
            'confirmed' => true,
        ])->assertSessionHasNoErrors();

        $mail->refresh();
        $this->assertNull($mail->task_id);
        $this->assertSame('unassigned', $withdrawnTask->refresh()->execution_status);
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_id' => $mail->correspondence_id,
            'task_id' => $withdrawnTask->id,
            'active' => false,
        ]);

        $this->actingAs($ps)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.assignment.task_id', $withdrawnTask->id)
                ->where('selectedMail.assignment.is_withdrawn', true)
                ->where('selectedMail.can_assign_outgoing', true)
                ->where('selectedMail.can_file', true));

        $this->actingAs($ps)->post(route('mail.assign-outgoing', $mail), [
            'assigned_to_user_id' => $replacementOfficer->id,
            'instructions' => 'Continue the follow-up from the preserved withdrawal history.',
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $replacementTask = Task::query()->latest('id')->firstOrFail();
        $this->assertSame(2, Task::count());
        $this->assertSame($replacementTask->id, $mail->refresh()->task_id);
        $this->assertSame($replacementOfficer->id, $replacementTask->responsible_user_id);
        $this->assertSame('unassigned', $withdrawnTask->refresh()->execution_status);
        $this->assertDatabaseHas('correspondence_recipients', [
            'correspondence_id' => $mail->correspondence_id,
            'task_id' => $replacementTask->id,
            'user_id' => $replacementOfficer->id,
            'active' => true,
        ]);
    }

    public function test_withdrawn_outgoing_mail_can_be_filed_without_losing_its_assignment_history(): void
    {
        $ps = User::factory()->role(Role::Ps)->create(['full_name' => 'Permanent Secretary']);
        $officer = User::factory()->role(Role::Officer)->create(['full_name' => 'Follow-up Officer']);
        $mail = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $ps->id,
            'subject' => 'Response requiring no further action',
            'sent_date' => today()->toDateString(),
            'status' => 'dispatched',
        ]);

        $this->actingAs($ps)->post(route('mail.assign-outgoing', $mail), [
            'assigned_to_user_id' => $officer->id,
            'instructions' => 'Confirm whether any further action is required.',
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();
        $withdrawnTask = Task::firstOrFail();

        $this->actingAs($ps)->post(route('tasks.workflow.unassign', $withdrawnTask), [
            'user_ids' => [$officer->id],
            'reason' => 'No further action is required.',
            'confirmed' => true,
        ])->assertSessionHasNoErrors();

        $this->actingAs($ps)->post(route('mail.file', $mail), [
            'filing_category' => 'Completed follow-up',
            'note' => 'Filed after the assignment was withdrawn.',
        ])->assertSessionHasNoErrors();

        $mail->refresh();
        $this->assertSame('filed', $mail->status->value);
        $this->assertSame('filed', $mail->correspondence->current_status->value);
        $this->assertSame('Completed follow-up', $mail->correspondence->filing_category);
        $this->assertSame('Filed after the assignment was withdrawn.', $mail->correspondence->filing_note);
        $this->assertSame('unassigned', $withdrawnTask->refresh()->execution_status);
        $this->assertDatabaseHas('correspondence_updates', [
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'filed',
            'status_to' => 'filed',
            'performed_by_user_id' => $ps->id,
        ]);
        $this->assertDatabaseHas('task_unassignments', [
            'task_id' => $withdrawnTask->id,
            'user_id' => $officer->id,
            'reason' => 'No further action is required.',
        ]);
    }
}
