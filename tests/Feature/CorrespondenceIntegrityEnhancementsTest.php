<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AnnotationTitle;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CorrespondenceIntegrityEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_search_returns_only_precise_normalized_subject_matches_across_directions(): void
    {
        $viewer = User::factory()->role(Role::Ps)->create();
        $incoming = MailRecord::factory()->incoming()->create([
            'subject' => 'Request for Approval of Procurement Plan',
            'sender_name' => 'Public Service Commission',
            'recipient_name' => 'Permanent Secretary',
            'correspondence_reference' => 'PSC/HR/26',
            'received_date' => '2026-08-18',
        ]);
        MailRecord::factory()->outgoing()->create(['subject' => 'Procurement Update']);
        MailRecord::factory()->outgoing()->create(['subject' => 'Request for Approval']);

        $this->actingAs($viewer)->getJson(route('mail.duplicate-search', [
            'subject' => '  request for approval: of procurement plan!  ',
            'sender_name' => 'Public Service Commission',
            'recipient_name' => 'Permanent Secretary',
            'correspondence_reference' => 'PSC/HR/26',
            'mail_date' => '2026-08-18',
        ]))->assertOk()
            ->assertJsonCount(1, 'duplicates')
            ->assertJsonPath('duplicates.0.id', $incoming->id)
            ->assertJsonPath('duplicates.0.direction', 'incoming')
            ->assertJsonPath('duplicates.0.match_strength', 3);
    }

    public function test_duplicate_search_hides_weak_partial_subject_matches(): void
    {
        $viewer = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->create(['subject' => 'Request for Approval of Procurement Plan']);
        MailRecord::factory()->outgoing()->create(['subject' => 'Procurement Update']);

        $this->actingAs($viewer)->getJson(route('mail.duplicate-search', [
            'subject' => 'Request for Approval',
        ]))->assertOk()->assertJsonCount(0, 'duplicates');
    }

    public function test_duplicate_search_never_exposes_mail_outside_the_viewers_scope(): void
    {
        $owner = User::factory()->role(Role::Clerk)->create();
        $otherClerk = User::factory()->role(Role::Clerk)->create();
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $owner->id,
            'subject' => 'Confidential recruitment request',
        ]);

        $this->actingAs($otherClerk)->getJson(route('mail.duplicate-search', [
            'subject' => 'Confidential recruitment request',
        ]))->assertOk()->assertJsonCount(0, 'duplicates');
    }

    public function test_annotation_titles_are_shared_searchable_and_normalized_duplicates_are_reused(): void
    {
        $first = User::factory()->role(Role::Officer)->create();
        $second = User::factory()->role(Role::Officer)->create();

        $created = $this->actingAs($first)->postJson(route('annotation-titles.store'), [
            'shorthand' => ' C/LIB ',
            'full_title' => 'Commissioner  Library Services',
        ])->assertCreated()->json('title');

        $this->actingAs($second)->getJson(route('annotation-titles.index', ['q' => 'Library Services']))
            ->assertOk()
            ->assertJsonPath('titles.0.id', $created['id'])
            ->assertJsonPath('titles.0.shorthand', 'C/LIB');

        $this->actingAs($second)->postJson(route('annotation-titles.store'), [
            'shorthand' => 'c / lib',
            'full_title' => ' commissioner library services ',
        ])->assertOk()
            ->assertJsonPath('existing', true)
            ->assertJsonPath('title.id', $created['id']);

        $this->assertSame(1, AnnotationTitle::query()->where('normalized_shorthand', 'clib')->count());
    }

    public function test_ps_es_is_available_by_default_and_slash_shorthands_are_reused(): void
    {
        $user = User::factory()->role(Role::Clerk)->create();
        $source = AnnotationTitle::query()->where('normalized_shorthand', 'pses')->firstOrFail();

        $this->assertSame('PS/ES', $source->shorthand);
        $this->assertSame('Permanent Secretary / Education and Sports', $source->full_title);
        $this->assertTrue($source->active);

        $this->actingAs($user)->getJson(route('annotation-titles.index', ['q' => 'PS/ES']))
            ->assertOk()
            ->assertJsonPath('titles.0.id', $source->id)
            ->assertJsonPath('titles.0.shorthand', 'PS/ES');

        $this->actingAs($user)->postJson(route('annotation-titles.store'), [
            'shorthand' => 'ps / es',
            'full_title' => 'Permanent Secretary / Education and Sports',
        ])->assertOk()
            ->assertJsonPath('existing', true)
            ->assertJsonPath('title.id', $source->id);

        $this->assertSame(1, AnnotationTitle::query()->where('normalized_shorthand', 'pses')->count());
    }

    public function test_requested_shared_shorthand_directory_is_complete_active_and_idempotent(): void
    {
        $this->assertDatabaseCount('annotation_titles', 81);

        $expected = [
            'pses' => ['PS/ES', 'Permanent Secretary / Education and Sports'],
            'psessecretary' => ['PS/ES-SECRETARY', 'PS/ES - Secretary'],
            'accimsecretary' => ['AC/CIM-SECRETARY', 'AC/CIM - Secretary'],
            'icthelpdeskembassy' => ['ICT HELPDESK-EMBASSY', 'ICT Helpdesk-Embassy'],
            'chrm' => ['C/HRM', 'Commissioner Human Resource Management'],
            'cbe' => ['C/BE', 'Commissioner Basic Education'],
            'ctvetom' => ['C/TVET O&M', 'C/TVET O&M'],
            'acadmissions' => ['AC/ADMISSIONS', 'AC/Admissions'],
        ];

        foreach ($expected as $normalized => [$shorthand, $fullTitle]) {
            $this->assertDatabaseHas('annotation_titles', [
                'normalized_shorthand' => $normalized,
                'shorthand' => $shorthand,
                'full_title' => $fullTitle,
                'active' => true,
            ]);
        }

        AnnotationTitle::query()->where('normalized_shorthand', 'accim')->update(['active' => false]);
        $migration = require database_path('migrations/2026_08_19_000004_add_requested_shared_annotation_titles.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('annotation_titles', 81);
        $this->assertDatabaseHas('annotation_titles', [
            'normalized_shorthand' => 'accim',
            'active' => true,
        ]);
    }

    public function test_reusing_an_inactive_shared_source_reactivates_it_before_selection(): void
    {
        $user = User::factory()->role(Role::Clerk)->create();
        $source = AnnotationTitle::create([
            'shorthand' => 'D/TVET',
            'full_title' => 'Director Technical and Vocational Education and Training',
            'active' => false,
        ]);

        $this->actingAs($user)->postJson(route('annotation-titles.store'), [
            'shorthand' => 'd / tvet',
            'full_title' => 'Director Technical and Vocational Education and Training',
        ])->assertOk()
            ->assertJsonPath('existing', true)
            ->assertJsonPath('reactivated', true)
            ->assertJsonPath('title.id', $source->id);

        $this->assertTrue($source->refresh()->active);
        $this->assertSame($user->id, $source->updated_by_user_id);
    }

    public function test_annotation_title_snapshots_are_saved_with_the_immutable_annotation(): void
    {
        $department = Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $department->id]);
        $task = Task::factory()->create([
            'assigned_by_user_id' => $commissioner->id,
            'assigned_to_user_id' => $commissioner->id,
            'department_id' => $department->id,
        ]);
        $origin = AnnotationTitle::create(['shorthand' => 'PS', 'full_title' => 'Permanent Secretary', 'active' => true]);
        $recipient = AnnotationTitle::query()->where('normalized_shorthand', 'chrm')->firstOrFail();

        $this->actingAs($commissioner)->post(route('tasks.annotations.store', $task), [
            'text' => 'Please review and advise.',
            'origin_title_id' => $origin->id,
            'recipient_title_id' => $recipient->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'action_type' => 'Annotated',
            'annotation_origin_snapshot' => 'PS — Permanent Secretary',
            'annotation_recipient_snapshot' => 'C/HRM — Commissioner Human Resource Management',
        ]);
    }

    public function test_notification_centre_counts_opens_and_marks_notifications_read(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        $first = Notification::create([
            'user_id' => $user->id,
            'type' => 'annotation',
            'message' => 'Annotation Added',
            'detail' => 'C/HRM added an annotation.',
            'action_url' => route('home'),
            'is_read' => false,
            'created_at' => now(),
        ]);
        $second = Notification::create([
            'user_id' => $user->id,
            'type' => 'correspondence_forwarded',
            'message' => 'Mail Forwarded',
            'detail' => 'A correspondence was forwarded to your office.',
            'action_url' => route('home'),
            'is_read' => false,
            'created_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('notifications/index')
                ->where('notifications.unread_count', 2)
                ->where('notificationsPage.meta.total', 2)
                ->where('notificationsPage.data.0.id', $first->id));

        $this->actingAs($user)->post(route('notifications.read', $first))->assertRedirect();
        $this->assertTrue($first->refresh()->is_read);
        $this->assertFalse($second->refresh()->is_read);

        $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertTrue($second->refresh()->is_read);
    }

    public function test_capture_submission_token_prevents_replayed_form_requests(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        $payload = [
            'submission_token' => '38ed3449-1645-4b38-8b6a-4a2b378b58af',
            'sender_name' => 'Public Service Commission',
            'recipient_name' => 'Permanent Secretary',
            'subject' => 'Unique request protected from double submission',
            'received_date' => today()->toDateString(),
            'confidentiality' => 'normal',
        ];

        $this->actingAs($clerk)->post(route('mail.incoming.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($clerk)->post(route('mail.incoming.store'), $payload)->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('mail_records', 1);
    }
}
