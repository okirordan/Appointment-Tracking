<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailListQueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_basic_home_does_not_load_unused_register_or_dashboard_data(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->create(['captured_by_user_id' => $ps->id]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->actingAs($ps)->get('/home?mode=basic')->assertOk();
        $page = $response->inertiaPage();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('mail/index', $page['component']);
        $this->assertSame([], $page['props']['mails']['data']);
        $this->assertArrayNotHasKey('stats', $page['props']);
        $this->assertArrayNotHasKey('recentMail', $page['props']);
        $this->assertArrayNotHasKey('openActionCount', $page['props']);
        $this->assertArrayNotHasKey('mail-home', $page['deferredProps'] ?? []);
        $this->assertArrayNotHasKey('mail-summary', $page['deferredProps'] ?? []);
        $this->assertSame([], collect($queries)
            ->filter(fn (array $query) => str_starts_with(
                trim(explode(' from ', strtolower($query['query']), 2)[1] ?? ''), '"mail_records"',
            ))
            ->pluck('query')->values()->all());
    }

    public function test_basic_detail_loads_the_selected_record_without_a_surrounding_register_page(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $ps->id]);

        $this->actingAs($ps)->get(route('mail.show', [$mail, 'mode' => 'basic']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedMail.id', $mail->id)
                ->has('mails.data', 0));
    }

    public function test_register_summary_is_deferred_on_the_initial_response(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();

        $response = $this->actingAs($ps)->get(route('mail.incoming.index'))->assertOk();
        $page = $response->inertiaPage();

        $this->assertArrayHasKey('mails', $page['props']);
        $this->assertArrayNotHasKey('stats', $page['props']);
        $this->assertContains('stats', $response->viewData('page')['deferredProps']['mail-summary']);

        $stats = $this->withHeaders([
            'X-Inertia-Partial-Component' => 'mail/index',
            'X-Inertia-Partial-Data' => 'stats',
        ])->get(route('mail.incoming.index'))->assertOk()->inertiaProps('stats');
        $this->assertSame(0, $stats['incoming_total']);
    }

    public function test_incoming_register_does_not_check_access_separately_for_every_row(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->count(10)->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'PS/ES',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($ps)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('mails.data', 10));

        $accessChecks = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'exists(select * from "mail_records"'))
            ->count();

        DB::disableQueryLog();
        $this->assertSame(0, $accessChecks, "The register issued {$accessChecks} individual mail access checks.");
    }

    public function test_clerk_register_uses_batched_access_checks(): void
    {
        $clerk = User::factory()->role(Role::Clerk)->create();
        MailRecord::factory()->incoming()->count(10)->create([
            'captured_by_user_id' => $clerk->id,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($clerk)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('mails.data', 10));

        $accessChecks = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'exists(select * from "mail_records"'))
            ->count();

        DB::disableQueryLog();
        $this->assertSame(0, $accessChecks, "The register issued {$accessChecks} individual mail access checks.");
    }

    public function test_partial_mail_reload_does_not_rebuild_the_recipient_filter(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'PS/ES',
        ]);

        $this->actingAs($ps)->get(route('mail.incoming.index'))->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($ps)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'mail/index',
            'X-Inertia-Partial-Data' => 'filters,mails',
        ])->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertJsonPath('component', 'mail/index')
            ->assertJsonCount(1, 'props.mails.data');

        $recipientScans = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'distinct')
                && str_contains(strtolower($query['query']), 'recipient_name'))
            ->count();

        DB::disableQueryLog();
        $this->assertSame(0, $recipientScans);
    }

    public function test_missing_mail_setting_is_cached_until_it_is_created(): void
    {
        cache()->forget('setting:mail_feature_optional_performance');
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame('0', Setting::value('mail_feature_optional_performance', '0'));
        $this->assertSame('0', Setting::value('mail_feature_optional_performance', '0'));

        $reads = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'from "settings"'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $reads);
        Setting::put('mail_feature_optional_performance', '1');
        $this->assertSame('1', Setting::value('mail_feature_optional_performance', '0'));
    }

    public function test_basic_recipient_directory_loads_on_demand_and_reuses_a_scoped_cache(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'PS/ES',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $full = $this->actingAs($ps)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipientOptions', []));
        $this->assertArrayNotHasKey('financialYearOptions', $full->inertiaPage()['props']);

        $initial = $this->actingAs($ps)->get(route('mail.incoming.index', ['mode' => 'basic']))->assertOk();
        $this->assertArrayNotHasKey('recipientOptions', $initial->inertiaPage()['props']);
        $this->assertArrayNotHasKey('mail-recipients', $initial->viewData('page')['deferredProps'] ?? []);

        $recipientScans = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'distinct')
                && str_contains(strtolower($query['query']), 'recipient_name'))
            ->count();
        $this->assertSame(0, $recipientScans);
        $this->assertSame(0, collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'distinct')
                && str_contains(strtolower($query['query']), 'financial_year'))->count());

        $partialHeaders = [
            'X-Inertia-Partial-Component' => 'mail/index',
            'X-Inertia-Partial-Data' => 'recipientOptions,recipientOptionLabels',
        ];
        DB::flushQueryLog();
        $this->withHeaders($partialHeaders)->get(route('mail.incoming.index', ['mode' => 'basic']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('recipientOptions', ['PS/ES'])
                ->has('recipientOptionLabels.PS/ES'));
        $this->assertSame(1, collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'distinct')
                && str_contains(strtolower($query['query']), 'recipient_name'))->count());

        DB::flushQueryLog();
        $this->withHeaders($partialHeaders)->get(route('mail.incoming.index', ['mode' => 'basic']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipientOptions', ['PS/ES']));
        $this->assertSame(0, collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'distinct')
                && str_contains(strtolower($query['query']), 'recipient_name'))->count());
        DB::disableQueryLog();

        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'Permanent Secretary',
        ]);
        $this->withHeaders($partialHeaders)->get(route('mail.incoming.index', ['mode' => 'basic']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipientOptions', ['PS/ES', 'Permanent Secretary']));
    }

    public function test_cached_recipient_options_remain_private_to_each_clerk(): void
    {
        $firstClerk = User::factory()->role(Role::Clerk)->create();
        $secondClerk = User::factory()->role(Role::Clerk)->create();
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $firstClerk->id,
            'recipient_name' => 'First office',
        ]);
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $secondClerk->id,
            'recipient_name' => 'Second office',
        ]);

        $this->withHeaders([
            'X-Inertia-Partial-Component' => 'mail/index',
            'X-Inertia-Partial-Data' => 'recipientOptions',
        ]);
        $this->actingAs($firstClerk)->get(route('mail.incoming.index', ['mode' => 'basic']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('recipientOptions', ['First office']));
        $this->actingAs($secondClerk)->get(route('mail.incoming.index', ['mode' => 'basic']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('recipientOptions', ['Second office']));
    }

    public function test_register_summary_uses_a_bounded_number_of_mail_aggregates(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->count(3)->create(['captured_by_user_id' => $ps->id]);
        MailRecord::factory()->outgoing()->count(2)->create([
            'captured_by_user_id' => $ps->id,
            'sender_name' => 'Permanent Secretary',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($ps)->withHeaders([
            'X-Inertia-Partial-Component' => 'mail/index',
        ])->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.incoming_total', 3)
                ->where('stats.outgoing_total', 2));

        $aggregateQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'from "mail_records"')
                && str_contains(strtolower($query['query']), 'count('))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $aggregateQueries);
    }

    public function test_mail_detail_reuses_the_loaded_correspondence_recipients(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $mail = MailRecord::factory()->incoming()->create(['captured_by_user_id' => $ps->id]);

        $this->actingAs($ps)->get(route('mail.show', $mail))->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($ps)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'mail/index',
            'X-Inertia-Partial-Data' => 'selectedMail',
        ])->get(route('mail.show', $mail))
            ->assertOk()
            ->assertJsonPath('props.selectedMail.id', $mail->id);

        $recipientQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'from "correspondence_recipients"'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $recipientQueries);
    }

    public function test_incoming_date_filter_still_matches_the_selected_day(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $matching = MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'Permanent Secretary',
            'received_date' => '2025-07-10',
        ]);
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'Permanent Secretary',
            'received_date' => '2025-07-11',
        ]);

        $this->actingAs($ps)->get(route('mail.incoming.index', [
            'date_from' => '2025-07-10',
            'date_to' => '2025-07-10',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('mails.data', 1)
            ->where('mails.data.0.id', $matching->id));
    }

    public function test_mail_cursor_pagination_moves_forward_and_back_without_an_exact_count(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->count(27)->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'Permanent Secretary',
            'received_date' => '2025-07-10',
        ]);
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'Permanent Secretary',
            'received_date' => null,
        ]);

        $first = $this->actingAs($ps)->get(route('mail.incoming.index'))->assertOk()->inertiaProps('mails');
        $this->assertCount(25, $first['data']);
        $this->assertArrayNotHasKey('total', $first['meta']);
        $this->assertNull($first['meta']['previous_cursor']);
        $this->assertNotNull($first['meta']['next_cursor']);

        $second = $this->actingAs($ps)->get(route('mail.incoming.index', [
            'cursor' => $first['meta']['next_cursor'],
        ]))->assertOk()->inertiaProps('mails');
        $this->assertCount(3, $second['data']);
        $this->assertNotNull($second['meta']['previous_cursor']);
        $this->assertNull($second['meta']['next_cursor']);
        $this->assertSame(
            MailRecord::query()->whereNull('received_date')->value('id'),
            $second['data'][2]['id'],
        );

        $back = $this->actingAs($ps)->get(route('mail.incoming.index', [
            'cursor' => $second['meta']['previous_cursor'],
        ]))->assertOk()->inertiaProps('mails');
        $this->assertSame(array_column($first['data'], 'id'), array_column($back['data'], 'id'));

    }
}
