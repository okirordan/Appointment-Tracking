<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ps_can_view_officer_performance_detail()
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $officer = User::factory()->role(Role::Officer)->create(['full_name' => 'Viewable Officer']);
        $supervisor = User::factory()->role(Role::Commissioner)->create();

        Task::factory()->create([
            'assigned_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => $officer->id,
        ]);

        $this->actingAs($ps)->get("/officer-performance/{$officer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('oversight/officer-performance')
                ->where('selected.full_name', 'Viewable Officer'));
    }

    public function test_ps_can_view_performance_of_a_deactivated_officer()
    {
        // The original bug: viewing a deactivated officer's record 403'd.
        $ps = User::factory()->role(Role::Ps)->create();
        $inactive = User::factory()->role(Role::Officer)->inactive()->create(['full_name' => 'Former Officer']);

        $this->actingAs($ps)->get("/officer-performance/{$inactive->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('oversight/officer-performance')
                ->where('selected.full_name', 'Former Officer'));
    }

    public function test_ps_can_view_deactivated_officer_via_lookup()
    {
        $ps = User::factory()->role(Role::Ps)->create();
        $inactive = User::factory()->role(Role::Officer)->inactive()->create(['full_name' => 'Former Officer']);

        $this->actingAs($ps)->get("/officer-lookup?q=former&officer={$inactive->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('oversight/officer-lookup')
                ->where('selected.full_name', 'Former Officer'));
    }

    public function test_commissioner_lookup_stays_within_their_department()
    {
        // Department scope is still enforced for Commissioners/Secretaries:
        // an out-of-department officer's detail is not returned.
        $deptA = \App\Models\Department::factory()->create();
        $deptB = \App\Models\Department::factory()->create();
        $commissioner = User::factory()->role(Role::Commissioner)->create(['department_id' => $deptA->id]);
        $outside = User::factory()->role(Role::Officer)->create(['department_id' => $deptB->id, 'full_name' => 'Outside Officer']);

        $this->actingAs($commissioner)->get("/officer-lookup?q=outside&officer={$outside->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('oversight/officer-lookup')
                ->where('selected', null));
    }

    public function test_unauthorized_role_sees_friendly_error_page_not_a_raw_trace()
    {
        // With debug off (production behaviour) a 403 renders the in-app
        // error page with a professional message, never a stack trace.
        config(['app.debug' => false]);

        $officer = User::factory()->role(Role::Officer)->create();
        $target = User::factory()->role(Role::Officer)->create();

        $response = $this->actingAs($officer)->get("/officer-performance/{$target->id}");

        $response->assertForbidden();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 403));
    }

    public function test_missing_page_renders_friendly_404_page()
    {
        config(['app.debug' => false]);

        $user = User::factory()->role(Role::Ps)->create();

        $response = $this->actingAs($user)->get('/officer-performance/999999');

        // A missing model → 404, rendered as the in-app error page.
        $response->assertNotFound();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 404));
    }

    public function test_error_page_details_are_not_leaked_to_users()
    {
        config(['app.debug' => false]);

        $officer = User::factory()->role(Role::Officer)->create();
        $target = User::factory()->role(Role::Officer)->create();

        $response = $this->actingAs($officer)->get("/officer-performance/{$target->id}");

        // No framework exception class names or stack-trace markers leak.
        $response->assertDontSee('UnauthorizedException');
        $response->assertDontSee('Stack trace');
        $response->assertDontSee('vendor\\laravel');
    }
}
