<?php

namespace Tests\Feature;

use App\Enums\CorrespondenceStatus;
use App\Enums\Role;
use App\Models\AssignmentView;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\MailAttachment;
use App\Models\MailRecord;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Models\UserPosition;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class SystemImprovementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_handles_sender_shorthand_whitespace_dates_and_assignment_annotations(): void
    {
        $viewer = User::factory()->role(Role::Ps)->create();
        $task = Task::factory()->create();
        $mail = MailRecord::factory()->incoming()->create([
            'sender_name' => 'PS/ES Coordination Secretariat',
            'sender_organisation' => 'Education Service Commission',
            'recipient_name' => 'Office of the Permanent Secretary',
            'subject' => 'School rehabilitation programme',
            'received_date' => '2026-08-05',
            'task_id' => $task->id,
        ]);
        TaskHistory::create([
            'task_id' => $task->id,
            'action_type' => 'Annotated',
            'note' => 'Prepare the reconstruction funding dossier.',
            'performed_by_user_id' => $viewer->id,
            'performed_by_name_snapshot' => $viewer->full_name,
            'performed_by_role' => $viewer->role->value,
            'created_at' => now(),
        ]);

        foreach (['  ps/es   coordination  ', 'Education Service', 'reconstruction dossier', '05/08/2026'] as $term) {
            $this->assertSame(
                1,
                MailRecord::query()->where('direction', 'incoming')->matchingKeywords($term)->count(),
                "The correspondence query did not match: {$term}",
            );
            $this->actingAs($viewer)
                ->get(route('mail.incoming.index', ['q' => $term]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('mails.data', 1)
                    ->where('mails.data.0.id', $mail->id));
        }
    }

    public function test_forwarding_creates_a_linked_outgoing_record_annotation_and_single_source_document(): void
    {
        Storage::fake('mail');
        Storage::fake('evidence');

        $department = Department::factory()->create();
        $clerk = User::factory()->role(Role::Clerk)->create(['full_name' => 'Registry Clerk']);
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $unrelated = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'subject' => 'Teacher deployment response',
            'details' => 'Review staffing gaps and prepare the formal response.',
            'correspondence_reference' => 'ESC/TD/084',
        ]);
        Storage::disk('mail')->put('incoming/source.pdf', 'original correspondence');
        $original = MailAttachment::create([
            'mail_record_id' => $mail->id,
            'original_filename' => 'source.pdf',
            'storage_key' => 'incoming/source.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 23,
            'checksum' => hash('sha256', 'original correspondence'),
            'uploaded_by_user_id' => $clerk->id,
            'uploaded_at' => now(),
        ]);

        $response = $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'target_type' => 'individual',
            'assigned_to_user_id' => $officer->id,
            'priority' => 'high',
            'due_date' => today()->addDays(5)->toDateString(),
            'instructions' => 'Draft a response and return it for review before dispatch.',
            'attachments' => [UploadedFile::fake()->create('briefing-note.pdf', 20, 'application/pdf')],
        ]);

        $task = Task::firstOrFail();
        $outgoing = MailRecord::query()->where('source_mail_record_id', $mail->id)->firstOrFail();

        $response->assertSessionHasNoErrors()->assertRedirect(route('tasks.show', $task, absolute: false));
        $this->assertSame($task->id, $mail->refresh()->task_id);
        $this->assertSame(CorrespondenceStatus::Assigned, $mail->status);
        $this->assertSame('outgoing', $outgoing->direction);
        $this->assertSame($task->id, $outgoing->routing_task_id);
        $this->assertSame($mail->id, $outgoing->source_mail_record_id);
        $this->assertSame($task->reference, $outgoing->dispatch_reference);
        $this->assertSame('Draft a response and return it for review before dispatch.', $outgoing->details);
        $this->assertSame(0, $outgoing->attachments()->count());
        $this->assertSame(1, $mail->attachments()->count());
        $this->assertSame(1, $task->evidence()->count());
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'action_type' => 'Annotated',
            'note' => 'Draft a response and return it for review before dispatch.',
        ]);
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'MailRecord', 'target_id' => $outgoing->id]);

        $this->actingAs($officer)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTask.mail_origin.mail_url', null)
                ->where('selectedTask.mail_origin.attachments.0.filename', 'source.pdf')
                ->where('selectedTask.mail_origin.attachments.0.download_url', route('mail.attachments.download', $original)));
        $this->actingAs($officer)->get(route('mail.attachments.download', $original))->assertOk();
        $this->actingAs($unrelated)->get(route('mail.attachments.download', $original))->assertForbidden();
    }

    public function test_forwarding_failure_rolls_back_the_task_mail_link_outgoing_record_and_audits(): void
    {
        $department = Department::factory()->create();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $clerk->id]);

        MailRecord::creating(function (MailRecord $candidate) {
            if ($candidate->direction === 'outgoing' && $candidate->source_mail_record_id !== null) {
                throw new RuntimeException('Simulated outgoing register failure.');
            }
        });

        $this->actingAs($clerk)->post(route('mail.assign', $mail), [
            'assigned_to_user_id' => $officer->id,
            'priority' => 'medium',
            'instructions' => 'This transaction must roll back.',
        ])->assertSessionHas('error');

        $mail->refresh();
        $this->assertNull($mail->task_id);
        $this->assertSame(CorrespondenceStatus::Registered, $mail->status);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('mail_records', 1);
        $this->assertSame(0, AuditLog::query()->where('target_type', 'Task')->count());
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_office_targets_are_searchable_and_follow_current_membership_dynamically(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $department = Department::factory()->create(['name' => 'Registry Services', 'code' => 'REG']);
        $office = OrganizationalUnit::create([
            'department_id' => $department->id,
            'type' => 'office',
            'name' => 'Central Registry Office',
            'code' => 'CRO',
            'active' => true,
        ]);
        $member = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $lateMember = User::factory()->role(Role::Officer)->create(['department_id' => $department->id]);
        $outsider = User::factory()->role(Role::Officer)->create();
        $position = Position::create([
            'organizational_unit_id' => $office->id,
            'role_id' => $member->roles()->firstOrFail()->id,
            'title' => 'Registry Officer',
            'hierarchy_level' => 300,
            'active' => true,
        ]);
        UserPosition::create([
            'user_id' => $member->id,
            'position_id' => $position->id,
            'is_primary' => true,
            'starts_at' => now()->subMinute(),
            'active' => true,
        ]);

        $this->actingAs($ps)->getJson(route('tasks.assignee-search', [
            'q' => 'Central Registry',
            'include_groups' => 1,
        ]))->assertOk()
            ->assertJsonFragment([
                'key' => 'office:'.$office->id,
                'target_type' => 'office',
                'full_name' => 'Central Registry Office',
            ]);

        $this->actingAs($ps)->post(route('tasks.store'), [
            'title' => 'Process the registry backlog',
            'target_type' => 'office',
            'organizational_unit_id' => $office->id,
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $task = Task::query()->where('title', 'Process the registry backlog')->firstOrFail();
        $this->assertSame('office', $task->assignment_target_type);
        $this->assertSame($office->id, $task->assigned_to_organizational_unit_id);
        $this->actingAs($member)->get(route('tasks.show', $task))->assertOk();
        $this->actingAs($outsider)->get(route('tasks.show', $task))->assertForbidden();

        UserPosition::create([
            'user_id' => $lateMember->id,
            'position_id' => $position->id,
            'is_primary' => true,
            'starts_at' => now()->subMinute(),
            'active' => true,
        ]);

        $this->actingAs($lateMember)->get(route('tasks.show', $task))->assertOk();
        $this->assertDatabaseHas('assignment_views', ['task_id' => $task->id, 'user_id' => $lateMember->id]);
    }

    public function test_each_recipient_gets_an_immutable_first_view_receipt_without_duplicate_notifications(): void
    {
        Carbon::setTestNow('2026-08-05 08:00:00');

        try {
            $assigner = User::factory()->role(Role::Ps)->create();
            $firstRecipient = User::factory()->role(Role::Officer)->create(['full_name' => 'First Recipient']);
            $secondRecipient = User::factory()->role(Role::Officer)->create(['full_name' => 'Second Recipient']);

            $this->actingAs($assigner)->post(route('tasks.store'), [
                'title' => 'Joint policy response',
                'target_type' => 'multiple',
                'assigned_to_user_ids' => [$firstRecipient->id, $secondRecipient->id],
                'priority' => 'high',
            ])->assertSessionHasNoErrors();
            $task = Task::query()->where('title', 'Joint policy response')->firstOrFail();

            Carbon::setTestNow('2026-08-05 08:05:00');
            $this->actingAs($firstRecipient)->get(route('tasks.show', $task))->assertOk();
            $firstViewedAt = AssignmentView::query()->whereBelongsTo($firstRecipient)->whereBelongsTo($task)->firstOrFail()->first_viewed_at;

            Carbon::setTestNow('2026-08-05 08:10:00');
            $this->actingAs($firstRecipient)->get(route('tasks.show', $task))->assertOk();

            Carbon::setTestNow('2026-08-05 08:15:00');
            $this->actingAs($secondRecipient)->get(route('tasks.show', $task))->assertOk();

            $task->refresh();
            $this->assertSame($firstRecipient->id, $task->first_viewed_by_user_id);
            $this->assertTrue($task->first_viewed_at->equalTo($firstViewedAt));
            $this->assertSame(2, AssignmentView::query()->where('task_id', $task->id)->count());
            $this->assertSame(2, AssignmentView::query()->where('task_id', $task->id)->where('user_id', $firstRecipient->id)->value('view_count'));
            $this->assertSame(2, TaskHistory::query()->where('task_id', $task->id)->where('action_type', 'Viewed')->count());
            $this->assertSame(2, Notification::query()->where('user_id', $assigner->id)->where('type', 'assignment_viewed')->count());
            $this->assertSame(2, NotificationDelivery::query()
                ->whereHas('notification', fn ($query) => $query->where('user_id', $assigner->id)->where('type', 'assignment_viewed'))
                ->where('channel', 'in_app')
                ->count());

            app(NotificationService::class)->notify(
                $assigner,
                'assignment_viewed',
                'Duplicate receipt attempt',
                null,
                $task,
                null,
                "assignment.viewed.{$task->id}.{$firstRecipient->id}",
                'assignment_views',
            );
            $this->assertSame(2, Notification::query()->where('user_id', $assigner->id)->where('type', 'assignment_viewed')->count());
        } finally {
            Carbon::setTestNow();
        }
    }
}
