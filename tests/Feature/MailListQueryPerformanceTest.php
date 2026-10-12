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

    public function test_full_mode_skips_the_basic_recipient_directory(): void
    {
        $ps = User::factory()->role(Role::Ps)->create();
        MailRecord::factory()->incoming()->create([
            'captured_by_user_id' => $ps->id,
            'recipient_name' => 'PS/ES',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($ps)->get(route('mail.incoming.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipientOptions', []));

        $recipientScans = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'distinct')
                && str_contains(strtolower($query['query']), 'recipient_name'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(0, $recipientScans);
        $this->actingAs($ps)->get(route('mail.incoming.index', ['mode' => 'basic']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recipientOptions', ['PS/ES']));
    }
}
