<?php

namespace Tests\Feature;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\MailAttachment;
use App\Models\MailRecord;
use App\Models\Notification;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_team_can_access_mail_registers_but_officers_cannot(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create();

        $this->actingAs($clerk)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('mail/index')
                ->where('direction', 'incoming')
                ->where('canManageRegister', true));

        $this->actingAs($clerk)->get(route('mail.outgoing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('direction', 'outgoing'));

        $this->actingAs($officer)->get(route('mail.incoming.index'))->assertForbidden();
    }

    public function test_ps_and_attached_secretary_accounts_can_search_scoped_incoming_and_outgoing_mail(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $incoming = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'sender_name' => 'Georgia Gorreti Nakalyowa',
            'recipient_name' => 'Permanent Secretary',
        ]);
        $outgoing = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $clerk->id,
            'recipient_name' => 'Office of the Auditor General',
            'sender_name' => 'Permanent Secretary',
        ]);

        foreach ([Role::Ps, Role::Secretary] as $role) {
            $viewer = User::factory()->role($role)->create();
            if ($role === Role::Secretary) {
                $ps = User::factory()->role(Role::Ps)->create([
                    'full_name' => 'Kedrace Turyagyenda',
                    'title' => 'Permanent Secretary',
                ]);
                SecretaryOfficeAttachment::create([
                    'secretary_user_id' => $viewer->id,
                    'supervisor_user_id' => $ps->id,
                    'official_job_title' => 'Senior Personal Secretary to the Permanent Secretary',
                    'starts_at' => now()->subMinute(),
                    'delegated_actions_permitted' => false,
                    'delegated_permissions' => [],
                    'active' => true,
                ]);
            }

            $this->actingAs($viewer)->get(route('mail.incoming.index', ['q' => 'Georgia Gorreti Nakalyowa']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('mails.data', 1)
                    ->where('mails.data.0.id', $incoming->id)
                    ->where('canManageRegister', true));

            $this->actingAs($viewer)->get(route('mail.outgoing.index', ['q' => 'Office Auditor General']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('mails.data', 1)
                    ->where('mails.data.0.id', $outgoing->id)
                    ->where('canManageRegister', true));
        }
    }

    public function test_outgoing_register_search_finds_imported_mail_by_every_supported_field(): void
    {
        $viewer = User::factory()->role(Role::Ps)->create();
        $outgoing = MailRecord::factory()->outgoing()->create([
            'external_id' => 'Book1 Outgoing Register:outgoing_mail:row:000041',
            'register_number' => 'OM-2026-00041',
            'sender_name' => 'Agnes Nakyoni',
            'sender_organisation' => 'International Schools Directorate',
            'recipient_name' => 'AC/GSE - Moses',
            'subject' => 'International academic competition travel permission',
            'details' => 'Students and teachers travelling for the competition',
            'correspondence_reference' => 'ISD/COMP/041',
            'registry_file_number' => 'TVET/OUT/2025/041',
            'letter_date' => '2025-10-07',
            'sent_date' => '2025-07-10',
        ]);

        foreach ([
            'outgoing',
            'OM-2026-00041',
            'Book1 Outgoing Register',
            'Agnes Nakyoni',
            'International Schools',
            'AC/GSE Moses',
            'academic competition',
            'Students teachers travelling',
            'ISD/COMP/041',
            'TVET/OUT/2025/041',
            '2025-10-07',
            '2025-07-10',
        ] as $term) {
            $this->actingAs($viewer)
                ->get(route('mail.outgoing.index', ['q' => $term]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('mails.data', 1)
                    ->where('mails.data.0.id', $outgoing->id)
                    ->where('mails.data.0.direction', 'outgoing'));
        }
    }

    public function test_secretary_mail_access_is_read_only(): void
    {
        $secretary = User::factory()->role(Role::Secretary)->create();
        $mail = MailRecord::factory()->incoming()->create();

        $this->actingAs($secretary)->post(route('mail.incoming.store'), [])->assertForbidden();
        $this->actingAs($secretary)->post(route('mail.outgoing.store'), [])->assertForbidden();
        $this->actingAs($secretary)->put(route('mail.incoming.update', $mail), [])->assertForbidden();
    }

    public function test_registry_team_can_edit_incoming_mail_and_the_before_after_trail_is_visible(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create(['full_name' => 'Registry Officer']);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'sender_name' => 'Georgia Nakalyowa',
            'sender_organisation' => null,
            'recipient_name' => 'Permanent Secretary',
            'subject' => 'Original subject',
            'details' => 'Original details',
            'correspondence_reference' => 'REF-001',
            'letter_date' => '2026-07-14',
            'received_date' => '2026-07-15',
            'receipt_method' => 'hand',
            'confidentiality' => 'normal',
            'registry_file_number' => null,
        ]);
        $originalRegisterNumber = $mail->register_number;

        $response = $this->actingAs($clerk)->put(route('mail.incoming.update', $mail), [
            'sender_name' => 'Georgia Gorreti Nakalyowa',
            'sender_organisation' => 'Education Service Commission',
            'recipient_name' => 'PS/ES',
            'subject' => 'Corrected subject',
            'details' => 'Original details',
            'correspondence_reference' => 'REF-001',
            'letter_date' => '2026-07-14',
            'received_date' => '2026-07-16',
            'receipt_method' => 'email',
            'confidentiality' => 'normal',
            'registry_file_number' => 'FILE-42',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('mail.show', $mail, absolute: false));
        $mail->refresh();
        $this->assertSame('Georgia Gorreti Nakalyowa', $mail->sender_name);
        $this->assertSame('Corrected subject', $mail->subject);
        $this->assertSame($originalRegisterNumber, $mail->register_number);

        $audit = AuditLog::query()
            ->where('target_type', 'MailRecord')
            ->where('target_id', $mail->id)
            ->where('action', 'like', 'Edited incoming mail%')
            ->firstOrFail();
        $this->assertSame($clerk->id, $audit->actor_user_id);
        $this->assertSame('Original subject', $audit->metadata_json['changes']['subject']['before']);
        $this->assertSame('Corrected subject', $audit->metadata_json['changes']['subject']['after']);

        $this->actingAs($clerk)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.can_edit', true)
                ->has('selectedMail.activity_history', 1)
                ->where('selectedMail.activity_history.0.performed_by', 'Registry Officer')
                ->where('selectedMail.activity_history.0.changes.2.field', 'Addressed to'));
    }

    public function test_outgoing_mail_cannot_be_changed_through_the_incoming_edit_route(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $mail = MailRecord::factory()->outgoing()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($clerk)->put(route('mail.incoming.update', $mail), [])->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_assigned_for_action_and_active_follow_up_filters_are_distinct(): void
    {
        $viewer = User::factory()->role(Role::Ps)->create();
        $activeTask = Task::factory()->create(['workflow_status' => TaskStatus::InProgress]);
        $completedTask = Task::factory()->create(['workflow_status' => TaskStatus::Completed]);
        $activeMail = MailRecord::factory()->incoming()->create(['task_id' => $activeTask->id]);
        MailRecord::factory()->incoming()->create(['task_id' => $completedTask->id]);

        $this->actingAs($viewer)->get(route('mail.incoming.index', ['status' => 'assigned_any']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'assigned_any')
                ->where('mails.meta.total', 2)
                ->where('stats.assigned_total', 2)
                ->where('stats.active_assignments', 1));

        $this->actingAs($viewer)->get(route('mail.incoming.index', ['status' => 'assigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'assigned')
                ->where('mails.meta.total', 1)
                ->where('mails.data.0.id', $activeMail->id));
    }

    public function test_incoming_mail_is_recorded_with_details_reference_and_private_attachment(): void
    {
        Storage::fake('mail');
        $clerk = User::factory()->role(Role::Clerk)->create();

        $response = $this->actingAs($clerk)->post(route('mail.incoming.store'), [
            'sender_name' => 'Georgia Nakalyowa',
            'sender_organisation' => 'Parent Teachers Association',
            'recipient_name' => 'Permanent Secretary',
            'subject' => 'Request for action on teacher housing',
            'details' => 'The writer requests an update on the teacher housing programme and a formal response.',
            'correspondence_reference' => 'PTA/2026/114',
            'letter_date' => today()->subDay()->toDateString(),
            'received_date' => today()->toDateString(),
            'receipt_method' => 'hand',
            'confidentiality' => 'normal',
            'attachments' => [UploadedFile::fake()->create('teacher-housing.pdf', 120, 'application/pdf')],
        ]);

        $mail = MailRecord::firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('mail.incoming.index', absolute: false));
        $this->assertSame('IM-'.now()->year.'-00001', $mail->register_number);
        $this->assertStringContainsString('formal response', $mail->details);
        $this->assertDatabaseHas('audit_logs', ['category' => 'mail', 'target_type' => 'MailRecord', 'target_id' => $mail->id]);

        $attachment = MailAttachment::firstOrFail();
        Storage::disk('mail')->assertExists($attachment->storage_key);

        $this->actingAs($clerk)->get(route('mail.incoming.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('mails.data.0.status', 'Registered')
                ->where('mails.data.0.status_class', 'st-received'));
    }

    public function test_outgoing_mail_has_its_own_register_and_details_field(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();

        $this->actingAs($clerk)->post(route('mail.outgoing.store'), [
            'sender_name' => 'Permanent Secretary',
            'recipient_name' => 'Commissioner Education Planning',
            'subject' => 'Response on the school expansion proposal',
            'details' => 'Provides the approved next steps and reporting requirements.',
            'correspondence_reference' => 'PS/OUT/2026/18',
            'sent_date' => today()->toDateString(),
            'confidentiality' => 'normal',
        ])->assertSessionHasNoErrors();

        $mail = MailRecord::firstOrFail();
        $this->assertSame('outgoing', $mail->direction);
        $this->assertSame('OM-'.now()->year.'-00001', $mail->register_number);
        $this->assertStringContainsString('reporting requirements', $mail->details);
        $this->actingAs($clerk)->post(route('mail.assign', $mail), [])->assertForbidden();
    }

    public function test_possible_duplicates_require_an_explicit_override_reason(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'sender_name' => 'Sender',
            'recipient_name' => 'Permanent Secretary',
            'subject' => 'Repeated letter',
            'details' => 'The same correspondence content.',
            'correspondence_reference' => 'REF-001',
            'received_date' => today()->toDateString(),
        ]);

        $payload = [
            'sender_name' => 'Sender',
            'recipient_name' => 'Permanent Secretary',
            'subject' => 'Repeated letter',
            'details' => 'The same correspondence content.',
            'correspondence_reference' => 'REF-001',
            'received_date' => today()->toDateString(),
            'confidentiality' => 'normal',
        ];

        $this->actingAs($clerk)->post(route('mail.incoming.store'), $payload)
            ->assertSessionHasErrors('duplicate_override');
        $this->assertSame(1, MailRecord::count());

        $this->actingAs($clerk)->post(route('mail.incoming.store'), [
            ...$payload,
            'duplicate_override' => true,
            'duplicate_reason' => '',
        ])->assertSessionHasErrors([
            'duplicate_reason' => 'Please briefly explain why this mail is not a duplicate before saving.',
        ]);
        $this->assertSame(1, MailRecord::count());

        $this->actingAs($clerk)->post(route('mail.incoming.store'), [
            ...$payload,
            'duplicate_override' => true,
            'duplicate_reason' => 'A separately delivered signed original.',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, MailRecord::count());
    }

    public function test_similar_source_or_reference_is_not_a_duplicate_when_content_or_date_differs(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'sender_name' => 'Georgia Gorreti Nakalyowa',
            'subject' => 'Request for teacher housing information',
            'details' => 'The sender requests the current implementation status.',
            'correspondence_reference' => 'REF-REUSED',
            'received_date' => today()->subDay()->toDateString(),
        ]);

        $this->actingAs($clerk)->post(route('mail.incoming.store'), [
            'sender_name' => 'Georgia Gorreti Nakalyowa',
            'recipient_name' => 'PS/ES',
            'subject' => 'Invitation to the annual education conference',
            'details' => 'The sender invites the Ministry to nominate two delegates.',
            'correspondence_reference' => 'REF-REUSED',
            'received_date' => today()->toDateString(),
            'confidentiality' => 'normal',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, MailRecord::count());
    }

    public function test_incoming_mail_assignment_atomically_creates_a_linked_task(): void
    {
        $department = Department::factory()->create();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'subject' => 'Teacher housing response',
            'details' => 'Prepare a detailed response covering the implementation status and outstanding gaps.',
        ]);

        $response = $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'department_id' => $department->id,
            'assigned_to_user_id' => $officer->id,
            'priority' => 'high',
            'due_date' => today()->addDays(7)->toDateString(),
            'instructions' => 'Submit the draft for PS review.',
        ]);

        $task = Task::firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('tasks.show', $task, absolute: false));
        $this->assertSame(AssignmentLevel::Ps, $task->assignment_level);
        $this->assertSame($officer->id, $task->assigned_to_user_id);
        $this->assertStringContainsString('implementation status', $task->description);
        $this->assertSame($task->id, $mail->refresh()->task_id);
        $this->assertSame(1, Notification::where('user_id', $officer->id)->count());

        $this->actingAs($officer)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTask.mail_origin.register_number', $mail->register_number)
                ->where('selectedTask.mail_origin.details', $mail->details));

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'department_id' => $department->id,
            'assigned_to_user_id' => $officer->id,
            'priority' => 'medium',
        ])->assertForbidden();
        $this->assertSame(1, Task::count());
    }

    public function test_department_commissioner_and_registry_staff_can_access_authorised_original_correspondence(): void
    {
        Storage::fake('mail');
        $department = Department::factory()->create();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $unrelated = User::factory()->role(Role::Officer)->create();
        $task = Task::factory()->level(AssignmentLevel::Ps)->create([
            'assigned_by_user_id' => $clerk->id,
            'assigned_to_user_id' => $commissioner->id,
            'department_id' => $department->id,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'task_id' => $task->id,
        ]);
        Storage::disk('mail')->put('1/source.pdf', 'test');
        $attachment = MailAttachment::create([
            'mail_record_id' => $mail->id,
            'original_filename' => 'source.pdf',
            'storage_key' => '1/source.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4,
            'checksum' => hash('sha256', 'test'),
            'uploaded_by_user_id' => $clerk->id,
            'uploaded_at' => now(),
        ]);

        // Department ownership grants the current Commissioner access to
        // the correspondence and its original attachments.
        $this->actingAs($commissioner)->get(route('mail.show', $mail))->assertOk();
        $this->actingAs($commissioner)->get(route('mail.attachments.preview', $attachment))->assertOk();
        $this->actingAs($commissioner)->get(route('mail.attachments.download', $attachment))->assertOk();
        // Unrelated officers never inherit correspondence access from a task.
        $this->actingAs($unrelated)->get(route('mail.show', $mail))->assertForbidden();
        $this->actingAs($unrelated)->get(route('mail.attachments.download', $attachment))->assertForbidden();

        // Explicitly authorised registry roles keep full access.
        $ps = User::factory()->role(Role::Ps)->create();
        $secretary = User::factory()->role(Role::Secretary)->create();
        SecretaryOfficeAttachment::create([
            'secretary_user_id' => $secretary->id,
            'supervisor_user_id' => $ps->id,
            'official_job_title' => 'Senior Personal Secretary to the Permanent Secretary',
            'starts_at' => now()->subMinute(),
            'delegated_actions_permitted' => false,
            'delegated_permissions' => [],
            'active' => true,
        ]);
        $this->actingAs($ps)->get(route('mail.show', $mail))->assertOk();
        $this->actingAs($ps)->get(route('mail.attachments.preview', $attachment))->assertOk();
        $this->actingAs($secretary)->get(route('mail.show', $mail))->assertOk();
    }

    public function test_task_detail_offers_the_original_correspondence_link_to_the_department_commissioner(): void
    {
        $department = Department::factory()->create();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $task = Task::factory()->level(AssignmentLevel::Ps)->create([
            'assigned_by_user_id' => $clerk->id,
            'assigned_to_user_id' => $commissioner->id,
            'department_id' => $department->id,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'task_id' => $task->id,
        ]);

        // The current department Commissioner receives a working link.
        $this->actingAs($commissioner)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTask.mail_origin.register_number', $mail->register_number)
                ->where('selectedTask.mail_origin.mail_url', route('mail.show', $mail)));

        // An authorised viewer gets the working link.
        $ps = User::factory()->role(Role::Ps)->create();
        $this->actingAs($ps)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTask.mail_origin.mail_url', route('mail.show', $mail)));
    }

    public function test_denied_correspondence_access_renders_a_friendly_in_app_message(): void
    {
        config(['app.debug' => false]);

        $clerk = User::factory()->role(Role::Clerk)->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        $this->actingAs($commissioner)->get(route('mail.show', $mail))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/error')
                ->where('status', 403)
                ->where('message', 'You do not have permission to view this correspondence.'));
    }

    public function test_register_page_reports_view_capability_for_safe_close_navigation(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        // Registry users close back to the register they came from; the
        // page needs to know the capability to avoid navigating anyone
        // to a URL that would 403.
        $this->actingAs($clerk)->get(route('mail.show', $mail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('mail/index')
                ->where('canViewRegister', true)
                ->where('selectedMail.id', $mail->id));
    }
}
