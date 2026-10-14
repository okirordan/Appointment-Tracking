<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\CorrespondenceAccessGrant;
use App\Models\MailRecord;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use App\Services\SearchCache;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login()
    {
        $this->get('/home')->assertRedirect('/login');
    }

    public function test_authenticated_users_see_home_with_their_role_navigation()
    {
        $user = User::factory()->role(Role::Ps)->create();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->where('auth.user.username', $user->username)
                ->where('auth.user.role', 'ps')
                ->where('auth.user.role_label', 'Permanent Secretary')
                ->count('nav', 7)
                ->where('nav.0.label', 'Search Mail')
                ->where('nav.0.href', route('home', ['type' => 'mail']))
                ->where('nav.1.label', 'Mails')
                ->where('nav.2.label', 'All Assignments')
                ->where('nav.6.label', 'Filed Correspondence'));
    }

    public function test_authenticated_app_shell_exposes_the_csrf_token_used_by_inline_json_requests(): void
    {
        $user = User::factory()->role(Role::Clerk)->create();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertSee('name="csrf-token"', false);
    }

    public function test_filed_correspondence_is_the_last_registry_navigation_item(): void
    {
        $user = User::factory()->role(Role::Clerk)->create();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->count('nav', 5)
                ->where('nav.4.label', 'Filed Correspondence'));
    }

    public function test_officer_navigation_is_limited_to_officer_pages()
    {
        $user = User::factory()->role(Role::Officer)->create();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->count('nav', 4)
                ->where('nav.0.label', 'Search Mail')
                ->where('nav.2.label', 'My Tasks')
                ->where('nav.3.label', 'Correspondence'));
    }

    public function test_department_leadership_navigation_consolidates_correspondence_under_department_work(): void
    {
        $user = User::factory()->role(Role::Commissioner)->create();

        $this->actingAs($user)
            ->get(route('correspondence.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->count('nav', 7)
                ->where('nav.0.label', 'Department Work')
                ->where('nav.0.active', true)
                ->where('nav.1.label', 'Mails')
                ->where('nav.2.label', 'Search Mail')
                ->where('nav.3.label', 'Tasks')
                ->where('nav.6.label', 'Filed Correspondence'));
    }

    public function test_registry_users_receive_mail_summary_counts_on_home(): void
    {
        $user = User::factory()->role(Role::Clerk)->create();
        $activeTask = Task::factory()->create(['workflow_status' => TaskStatus::InProgress]);
        $completedTask = Task::factory()->create(['workflow_status' => TaskStatus::Completed]);

        MailRecord::factory()->incoming()->count(2)->create(['captured_by_user_id' => $user->id]);
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $user->id,
            'task_id' => $activeTask->id,
        ]);
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $user->id,
            'task_id' => $completedTask->id,
        ]);
        MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $user->id,
            'sender_name' => 'Permanent Secretary',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($user)->get('/home')->assertOk();
        DB::flushQueryLog();

        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'home', 'X-Inertia-Partial-Data' => 'mailStats'])
            ->get('/home')
            ->assertOk()
            ->assertJsonPath('props.mailStats.incoming_total', 4)
            ->assertJsonPath('props.mailStats.awaiting_assignment', 2)
            ->assertJsonPath('props.mailStats.assigned_total', 2)
            ->assertJsonPath('props.mailStats.active_assignments', 1)
            ->assertJsonPath('props.mailStats.outgoing_total', 1);

        $mailAggregates = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'from "mail_records"')
                && str_contains(strtolower($query['query']), 'count('))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $mailAggregates);
    }

    public function test_non_registry_users_do_not_receive_inaccessible_mail_summary_links(): void
    {
        $user = User::factory()->role(Role::Officer)->create();

        $this->actingAs($user)->get('/home')->assertOk();

        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'home', 'X-Inertia-Partial-Data' => 'mailStats'])
            ->get('/home')
            ->assertOk()
            ->assertJsonPath('props.mailStats', null);
    }

    public function test_ps_and_attached_secretary_global_search_returns_scoped_incoming_and_outgoing_mail(): void
    {
        $psRecorder = User::factory()->role(Role::Ps)->create();
        $incoming = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $psRecorder->id,
            'sender_name' => 'Georgia Gorreti Nakalyowa',
            'recipient_name' => 'Permanent Secretary',
        ]);
        $outgoing = MailRecord::factory()->outgoing()->create([
            'captured_by_user_id' => $psRecorder->id,
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

            $this->actingAs($viewer)
                ->get('/home?q=Georgia%20Gorreti%20Nakalyowa&type=mail')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('type', 'mail')
                    ->where('results.total', 1)
                    ->has('results.mails', 1)
                    ->where('results.mails.0.id', $incoming->id)
                    ->where('results.mails.0.direction', 'incoming'));

            $this->actingAs($viewer)
                ->get('/home?q=Office%20Auditor%20General&type=mail')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('results.total', 1)
                    ->has('results.mails', 1)
                    ->where('results.mails.0.id', $outgoing->id)
                    ->where('results.mails.0.direction', 'outgoing'));
        }
    }

    public function test_global_search_forms_find_imported_outgoing_mail_by_extended_register_fields(): void
    {
        $viewer = User::factory()->role(Role::Ps)->create();
        $outgoing = MailRecord::factory()->outgoing()->create([
            'external_id' => 'Book1 Outgoing Register:outgoing_mail:row:000041',
            'registry_file_number' => 'TVET/OUT/2025/041',
            'sent_date' => '2025-07-10',
            'subject' => 'International academic competition travel permission',
        ]);

        foreach ([
            ['q' => 'International academic competition', 'type' => 'all'],
            ['q' => 'TVET/OUT/2025/041', 'type' => 'mail'],
            ['q' => 'Book1 Outgoing Register', 'type' => 'mail'],
            ['q' => '2025-07-10', 'type' => 'mail'],
        ] as $search) {
            $this->actingAs($viewer)
                ->get(route('home', $search))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('results.mails', 1)
                    ->where('results.mails.0.id', $outgoing->id)
                    ->where('results.mails.0.direction', 'outgoing'));
        }
    }

    public function test_mail_date_search_preserves_day_matching(): void
    {
        $mail = MailRecord::factory()->incoming()->create(['letter_date' => '2025-07-10']);
        $query = MailRecord::query()->matchingKeywords('10/07/2025');

        $this->assertSame([$mail->id], $query->pluck('id')->all());
    }

    public function test_indexed_search_keeps_mail_access_scope_and_returns_only_search_card_fields(): void
    {
        config()->set('ats.search.indexed_mail_documents', true);
        $ps = User::factory()->role(Role::Ps)->create();
        $outsider = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'Permanent Secretary',
            'subject' => 'Unique indexed budget review',
        ]);

        $this->actingAs($ps)->get('/home?q=Unique%20indexed%20budget&type=mail')
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.mails.0.id', $mail->id)
                ->where('results.mails.0.subject', 'Unique indexed budget review')
                ->where('results.mails.0.record_kind', 'Incoming'));

        $this->actingAs($outsider)->get('/home?q=Unique%20indexed%20budget&type=mail')
            ->assertInertia(fn (Assert $page) => $page->count('results.mails', 0));
    }

    public function test_indexed_mail_search_uses_next_and_previous_cursors(): void
    {
        config()->set('ats.search.indexed_mail_documents', true);
        $ps = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->count(27)->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'Permanent Secretary',
            'subject' => 'Distinctive cursor marker',
        ]);

        $first = $this->actingAs($ps)->get('/home?q=Distinctive%20cursor%20marker&type=mail')
            ->assertOk()->inertiaProps('results');
        $this->assertCount(25, $first['mails']);
        $this->assertNotNull($first['pagination']['next_cursor']);
        $this->assertNull($first['pagination']['previous_cursor']);

        $second = $this->get('/home?q=Distinctive%20cursor%20marker&type=mail&cursor='.urlencode($first['pagination']['next_cursor']))
            ->assertOk()->inertiaProps('results');
        $this->assertCount(2, $second['mails']);
        $this->assertNotNull($second['pagination']['previous_cursor']);
        $this->assertNull($second['pagination']['next_cursor']);

        $back = $this->get('/home?q=Distinctive%20cursor%20marker&type=mail&cursor='.urlencode($second['pagination']['previous_cursor']))
            ->assertOk()->inertiaProps('results');
        $this->assertSame(array_column($first['mails'], 'id'), array_column($back['mails'], 'id'));
    }

    public function test_secretary_navigation_includes_mail_register(): void
    {
        $user = User::factory()->role(Role::Secretary)->create();

        $this->actingAs($user)->get('/home')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->count('nav', 6)
                ->where('nav.0.label', 'Department Work')
                ->where('nav.1.label', 'Mails')
                ->where('nav.2.label', 'Search Mail')
                ->where('nav.2.href', route('home', ['type' => 'mail']))
                ->where('nav.3.label', 'Reports')
                ->where('nav.5.label', 'Filed Correspondence'));

        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'home', 'X-Inertia-Partial-Data' => 'mailStats'])
            ->get('/home')
            ->assertOk()
            ->assertJsonPath('props.mailStats.incoming_total', 0)
            ->assertJsonPath('props.mailStats.outgoing_total', 0);
    }

    public function test_search_payload_carries_per_page_and_counts_for_category_pagination(): void
    {
        $user = User::factory()->role(Role::Ps)->create();

        // "All" overview: no combined pagination, but per_page and counts
        // let the page render per-category pagination directly.
        $this->actingAs($user)
            ->get('/home?q=education')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.per_page', 25)
                ->has('results.counts')
                ->where('results.pagination', null));

        // Category view: paginated with meta for the numbered controls.
        $this->actingAs($user)
            ->get('/home?q=education&type=tasks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.pagination.current_page', 1)
                ->has('results.pagination.last_page')
                ->has('results.pagination.total'));
    }

    public function test_search_results_are_cached_and_model_changes_invalidate_them(): void
    {
        $user = User::factory()->role(Role::Ps)->create();
        $task = Task::factory()->create(['title' => 'Distinctive procurement roadmap']);
        $search = app(SearchService::class);

        $first = $search->search($user, 'Distinctive procurement', 'tasks');
        $this->assertSame($task->id, $first['tasks'][0]['id']);

        // A direct database write bypasses model events, proving the second
        // identical request is served from the result cache.
        DB::table('tasks')->where('id', $task->id)->update(['title' => 'Changed outside Eloquent']);
        $cached = $search->search($user, 'Distinctive procurement', 'tasks');
        $this->assertSame($task->id, $cached['tasks'][0]['id']);

        SearchCache::invalidate();
        $this->assertSame(0, $search->search($user, 'Distinctive procurement', 'tasks')['total']);

        $task->update(['title' => 'Model event searchable phrase']);
        $this->assertSame(1, $search->search($user, 'Model event searchable', 'tasks')['total']);

        $task->update(['title' => 'Renamed after cache fill']);
        $this->assertSame(0, $search->search($user, 'Model event searchable', 'tasks')['total']);
    }

    public function test_revoking_a_correspondence_grant_invalidates_cached_search_results(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $viewer = User::factory()->role(Role::Officer)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'subject' => 'Unique grant visibility phrase',
            'recipient_name' => 'Permanent Secretary',
        ]);
        $grant = CorrespondenceAccessGrant::create([
            'correspondence_id' => $mail->correspondence_id,
            'user_id' => $viewer->id,
            'access_level' => 'read',
            'granted_by_user_id' => $ps->id,
            'granted_at' => now(),
        ]);

        $search = app(SearchService::class);
        $this->assertSame($mail->id, $search->search($viewer, 'Unique grant visibility phrase', 'mail', false)['mails'][0]['id']);

        $grant->update(['revoked_at' => now(), 'revoked_by_user_id' => $ps->id]);

        $this->assertSame(0, $search->search($viewer, 'Unique grant visibility phrase', 'mail', false)['total']);
    }

    public function test_correspondence_status_change_refreshes_cached_home_mail_stats(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'Permanent Secretary',
        ]);

        $this->actingAs($ps)->get('/home')->assertOk();

        $this->actingAs($ps)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'home', 'X-Inertia-Partial-Data' => 'mailStats'])
            ->get('/home')->assertJsonPath('props.mailStats.incoming_total', 1);

        $mail->correspondence->update(['current_status' => 'filed']);

        $this->actingAs($ps)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'home', 'X-Inertia-Partial-Data' => 'mailStats'])
            ->get('/home')->assertJsonPath('props.mailStats.incoming_total', 0);
    }
}
