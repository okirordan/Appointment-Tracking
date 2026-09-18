<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LogRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdministrationLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_times_are_displayed_in_uganda_time_with_an_explicit_timezone(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-18 09:43:00', 'Africa/Kampala'));
        app(AuditLogger::class)->log('login', 'Signed in', $admin);

        $this->actingAs($admin)->get('/admin/audit-log?tab=users&category=login')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('logs.data.0.timestamp', '18 Sep 2026 09:43:00 EAT')
                ->where('logs.data.0.action', 'Signed in'));
        $this->travelBack();
    }

    public function test_sensitive_values_are_redacted_at_write_and_read_boundaries(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        app(AuditLogger::class)->log('user', 'Changed profile', $admin, metadata: ['password' => 'do-not-show', 'nested' => ['access_token' => 'token-value'], 'note' => 'password="hidden text" Bearer abc.def.xyz']);
        $entry = AuditLog::latest('id')->first();
        $this->assertStringNotContainsString('do-not-show', json_encode($entry->metadata_json));
        $this->assertStringNotContainsString('hidden text', json_encode($entry->metadata_json));
        $entry->update(['metadata_json' => ['password' => 'legacy-secret']]);
        $this->actingAs($admin)->get('/admin/audit-log?tab=activity')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/audit-log')->where('logs.data.0.details.password', '[redacted]'));
    }

    public function test_laravel_errors_and_failed_actions_are_separate_and_filterable(): void
    {
        $admin = User::factory()->role(Role::Sysadmin)->create();
        Log::error('Test application error', ['exception' => new \RuntimeException('token=secret-value')]);
        $this->actingAs($admin)->get('/admin/audit-log?tab=laravel&severity=error')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('logs.data.0.category', 'laravel')->where('logs.data.0.details.context.exception.message', 'token=[redacted]'));
        $officer = User::factory()->role(Role::Officer)->create();
        $this->actingAs($officer)->post('/admin/users', [])->assertForbidden();
        $this->actingAs($admin)->get('/admin/audit-log?tab=failed&category=admin&actor='.$officer->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('logs.data.0.category', 'failed_action')->where('logs.data.0.details.reason', 'Permission denied'));
    }

    public function test_non_admin_cannot_read_diagnostics_and_invalid_filters_are_rejected(): void
    {
        $this->actingAs(User::factory()->role(Role::Officer)->create())->get('/admin/audit-log')->assertForbidden();
        $this->actingAs(User::factory()->role(Role::Sysadmin)->create())->getJson('/admin/audit-log?from=not-a-date')->assertUnprocessable();
    }

    public function test_exception_trace_does_not_include_function_arguments(): void
    {
        $data = app(LogRedactor::class)->clean(new \RuntimeException('SQL: select password from users where token=secret'));
        $this->assertSame('SQL: [redacted]', $data['message']);
        foreach ($data['trace'] as $frame) {
            $this->assertArrayNotHasKey('args', $frame);
        }
    }
}
