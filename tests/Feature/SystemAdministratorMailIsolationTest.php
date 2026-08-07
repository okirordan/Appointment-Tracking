<?php

namespace Tests\Feature;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\MailAttachment;
use App\Models\MailRecord;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemAdministratorMailIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_system_administrator_cannot_access_ps_office_mail_even_with_explicit_mail_permissions(): void
    {
        Storage::fake('mail');

        $admin = User::factory()->role(Role::Sysadmin)->create(['full_name' => 'System Administrator']);
        $ps = User::factory()->role(Role::Ps)->create();
        $clerk = User::factory()->role(Role::Clerk)->create();
        $officer = User::factory()->role(Role::Officer)->create();
        $task = Task::factory()->level(AssignmentLevel::Ps)->create([
            'assigned_by_user_id' => $ps->id,
            'assigned_to_user_id' => $officer->id,
        ]);
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'office_supervisor_user_id' => $ps->id,
            'subject' => 'PS Office Restricted Alpha Correspondence',
        ]);
        $linkedMail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $clerk->id,
            'office_supervisor_user_id' => $ps->id,
            'task_id' => $task->id,
            'subject' => 'PS Office Restricted Linked Correspondence',
        ]);
        Storage::disk('mail')->put('restricted/source.pdf', 'restricted');
        $attachment = MailAttachment::create([
            'mail_record_id' => $linkedMail->id,
            'original_filename' => 'restricted-source.pdf',
            'storage_key' => 'restricted/source.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'checksum' => hash('sha256', 'restricted'),
            'uploaded_by_user_id' => $clerk->id,
            'uploaded_at' => now(),
        ]);

        $this->assertFalse($admin->getAllPermissions()->pluck('name')->contains(fn (string $name) => str_starts_with($name, 'mail.')));
        $admin->givePermissionTo(['mail.view', 'mail.manage', 'mail.assign']);
        $this->assertTrue($admin->can('mail.view'));
        $this->assertFalse($admin->can('viewAny', MailRecord::class));
        $this->assertFalse($admin->can('view', $mail));
        $this->assertFalse($admin->can('create', MailRecord::class));
        $this->assertFalse($admin->can('assign', $mail));

        $this->actingAs($admin)->get(route('mail.incoming.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('mail.outgoing.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('mail.show', $mail))->assertForbidden();
        $this->actingAs($admin)->post(route('mail.incoming.store'), [])->assertForbidden();
        $this->actingAs($admin)->post(route('mail.outgoing.store'), [])->assertForbidden();
        $this->actingAs($admin)->post(route('mail.assign', $mail), [])->assertForbidden();
        $this->actingAs($admin)->get(route('mail.attachments.download', $attachment))->assertForbidden();
        $this->actingAs($admin)->get(route('mail.attachments.preview', $attachment))->assertForbidden();

        $this->actingAs($admin)->get(route('tasks.show', $task))->assertForbidden();

        Notification::create([
            'user_id' => $admin->id,
            'type' => 'correspondence_assigned',
            'category' => 'correspondence_updates',
            'message' => 'Restricted correspondence notification',
            'related_mail_record_id' => $mail->id,
            'is_read' => false,
            'created_at' => now(),
        ]);
        Notification::create([
            'user_id' => $admin->id,
            'type' => 'system',
            'category' => 'new_assignments',
            'message' => 'Visible system notification',
            'is_read' => false,
            'created_at' => now()->addSecond(),
        ]);
        $this->assertNull(app(NotificationService::class)->notify(
            $admin,
            'correspondence_assigned',
            'Should not be delivered',
            null,
            null,
            $mail,
        ));

        $this->actingAs($admin)->get(route('home', [
            'q' => 'Restricted Linked Correspondence',
            'type' => 'mail',
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mailStats', null)
                ->where('results.counts.mails', 0)
                ->has('results.mails', 0)
                ->where('nav', fn ($nav) => collect($nav)->pluck('key')->doesntContain('mail'))
                ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->every(fn (string $permission) => ! str_starts_with($permission, 'mail.')))
                ->where('notifications.unread_count', 1)
                ->has('notifications.items', 1)
                ->where('notifications.items.0.message', 'Visible system notification'));

        $this->actingAs($ps)->get(route('mail.show', $mail))->assertOk();
        $this->actingAs($clerk)->get(route('mail.incoming.index'))->assertOk();

        AuditLog::create([
            'category' => 'mail',
            'action' => 'Restricted mail subject: PS Office Restricted Alpha Correspondence',
            'actor_user_id' => $clerk->id,
            'actor_name_snapshot' => $clerk->full_name,
            'outcome' => 'success',
            'created_at' => now(),
        ]);
        AuditLog::create([
            'category' => 'user',
            'action' => 'Visible administrator activity',
            'actor_user_id' => $admin->id,
            'actor_name_snapshot' => $admin->full_name,
            'outcome' => 'success',
            'created_at' => now()->addSecond(),
        ]);
        $this->actingAs($admin)->get(route('admin.audit.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories', fn ($categories) => collect($categories)->doesntContain('mail'))
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'Visible administrator activity'));
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recent_activity.data', 1)
                ->where('recent_activity.data.0.text', 'Visible administrator activity'));
    }

    public function test_system_administrator_cannot_list_preview_confirm_or_template_mail_imports(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $ps = User::factory()->role(Role::Ps)->create();
        $ps->givePermissionTo('admin.access');

        $mailBatch = $this->importBatch($ps, 'incoming_mail', 'PS mail.xlsx');
        $taskBatch = $this->importBatch($admin, 'tasks', 'tasks.xlsx');

        $this->actingAs($admin)->get(route('admin.imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entities', fn ($entities) => collect($entities)->doesntContain('incoming_mail') && collect($entities)->doesntContain('outgoing_mail'))
                ->has('batches.data', 1)
                ->where('batches.data.0.id', $taskBatch->id));
        $this->actingAs($admin)->get(route('admin.imports.show', $mailBatch))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.imports.confirm', $mailBatch))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.imports.template', ['entity' => 'incoming_mail']))->assertForbidden();

        $this->actingAs($ps)->get(route('admin.imports.show', $mailBatch))->assertOk();
        $this->actingAs($ps)->get(route('admin.imports.template', ['entity' => 'incoming_mail']))->assertOk();
    }

    public function test_system_administrator_switches_to_officer_mode_and_only_sees_assigned_correspondence(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create(['full_name' => 'Administrator Officer']);
        $ps = User::factory()->role(Role::Ps)->create();
        $assigned = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $ps->id]);
        $unassigned = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $ps->id]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.work_mode', 'administration')
                ->where('nav', fn ($nav) => collect($nav)->pluck('key')->contains('admin')
                    && ! collect($nav)->pluck('key')->contains('tasks')));
        $this->actingAs($admin)->get(route('correspondence.index'))->assertForbidden();

        $this->actingAs($admin)->post(route('work-mode.update'), ['mode' => 'officer'])
            ->assertRedirect(route('officer.dashboard'));

        $this->actingAs($admin)->withSession(['work_mode' => 'officer'])->get(route('officer.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.work_mode', 'officer')
                ->where('nav', fn ($nav) => collect($nav)->pluck('key')->contains('tasks')
                    && collect($nav)->pluck('key')->contains('correspondence')
                    && ! collect($nav)->pluck('key')->contains('admin')));

        $this->actingAs($ps)->post(route('mail.assign', $assigned), [
            'action_required' => true,
            'target_type' => 'individual',
            'assigned_to_user_ids' => [$admin->id],
            'priority' => 'medium',
        ])->assertSessionHasNoErrors();

        $task = $assigned->refresh()->task;
        $this->assertNotNull($task);
        $this->actingAs($admin)->withSession(['work_mode' => 'officer'])->get(route('tasks.show', $task))->assertOk();
        $this->actingAs($admin)->withSession(['work_mode' => 'officer'])->get(route('mail.show', $assigned))->assertOk();
        $this->actingAs($admin)->withSession(['work_mode' => 'officer'])->get(route('mail.show', $unassigned))->assertForbidden();
        $this->actingAs($admin)->withSession(['work_mode' => 'officer'])->get(route('correspondence.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $assigned->id));

        $this->actingAs($admin)->withSession(['work_mode' => 'officer'])->post(route('work-mode.update'), ['mode' => 'administration'])
            ->assertRedirect(route('admin.dashboard'));
    }

    private function importBatch(User $user, string $entity, string $filename): ImportBatch
    {
        return ImportBatch::create([
            'initiated_by_user_id' => $user->id,
            'source_system' => 'Isolation test',
            'entity_type' => $entity,
            'status' => 'ready',
            'original_filename' => $filename,
            'storage_key' => 'imports/'.$filename,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size_bytes' => 100,
            'checksum' => hash('sha256', $filename),
            'mapping_json' => [],
            'total_rows' => 0,
            'valid_rows' => 0,
            'created_rows' => 0,
            'updated_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
        ]);
    }
}
