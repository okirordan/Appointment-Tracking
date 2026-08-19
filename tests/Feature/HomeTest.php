<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\MailRecord;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use App\Services\SearchCache;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                ->where('nav.1.label', 'Mails')
                ->where('nav.2.label', 'Filed Correspondence')
                ->where('nav.3.label', 'All Assignments'));
    }

    public function test_authenticated_app_shell_exposes_the_csrf_token_used_by_inline_json_requests(): void
    {
        $user = User::factory()->role(Role::Clerk)->create();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertSee('name="csrf-token"', false);
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
                ->where('nav.2.label', 'My Tasks')
                ->where('nav.3.label', 'Correspondence'));
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
        MailRecord::factory()->outgoing()->create(['captured_by_user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mailStats.incoming_total', 4)
                ->where('mailStats.awaiting_assignment', 2)
                ->where('mailStats.assigned_total', 2)
                ->where('mailStats.active_assignments', 1)
                ->where('mailStats.outgoing_total', 1));
    }

    public function test_non_registry_users_do_not_receive_inaccessible_mail_summary_links(): void
    {
        $user = User::factory()->role(Role::Officer)->create();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('mailStats', null));
    }

    public function test_ps_and_attached_secretary_global_search_returns_scoped_incoming_and_outgoing_mail(): void
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

    public function test_secretary_navigation_includes_mail_register(): void
    {
        $user = User::factory()->role(Role::Secretary)->create();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->count('nav', 8)
                ->where('nav.1.label', 'Mails')
                ->where('nav.2.label', 'Filed Correspondence')
                ->where('nav.3.label', 'Supported Office')
                ->where('nav.4.label', 'Office Assignments')
                ->where('mailStats.incoming_total', 0)
                ->where('mailStats.outgoing_total', 0));
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
                ->where('results.per_page', 20)
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
}
