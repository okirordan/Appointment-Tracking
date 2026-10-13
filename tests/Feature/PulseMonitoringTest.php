<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\Pulse\AssignmentOperationsCard;
use App\Models\User;
use App\Pulse\AssignmentOperations;
use App\Pulse\ScheduledAssignmentTasks;
use App\Services\ImpersonationService;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\SlowOutgoingRequests;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PulseMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_system_administrators_in_administration_mode_can_open_pulse(): void
    {
        $this->get('/pulse')->assertRedirect('/login');

        foreach ([Role::Officer, Role::Ps] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/pulse')->assertForbidden();
        }

        $admin = User::factory()->role(Role::Sysadmin)->create();
        $this->actingAs($admin)->get('/pulse')->assertOk()->assertSee('ats.assignment-operations');
        $this->withSession(['work_mode' => 'officer'])->get('/pulse')->assertForbidden();
        $this->withSession(['work_mode' => 'administration', ImpersonationService::KEY => 1])
            ->get('/pulse')->assertRedirect('/login');
    }

    public function test_assignment_metrics_store_only_route_names_and_numbers(): void
    {
        $pulse = app(Pulse::class)->startRecording();
        $request = Request::create('/tasks/123/submit?token=private', 'POST');
        $route = new Route('POST', 'tasks/{task}/submit', fn () => null);
        $route->name('tasks.workflow.submit');
        $request->setRouteResolver(fn () => $route);
        $request->attributes->set('ats_pulse_queries', 7);

        (new AssignmentOperations($pulse))->record(
            Carbon::now()->subMilliseconds(30),
            $request,
            new Response('', 500),
        );
        $pulse->ingest();

        $this->assertDatabaseHas('pulse_entries', [
            'type' => 'assignment_operation',
            'key' => 'tasks.workflow.submit',
        ]);
        $this->assertDatabaseHas('pulse_entries', [
            'type' => 'assignment_queries',
            'key' => 'tasks.workflow.submit',
            'value' => 7,
        ]);
        $this->assertDatabaseHas('pulse_entries', [
            'type' => 'assignment_failure',
            'key' => 'tasks.workflow.submit',
            'value' => 500,
        ]);
        $this->assertDatabaseMissing('pulse_entries', ['key' => 'token=private']);

        Livewire::withoutLazyLoading()->test(AssignmentOperationsCard::class)
            ->assertSee('tasks.workflow.submit')
            ->assertSee('Queries / request');
    }

    public function test_real_assignment_listing_records_duration_and_query_count(): void
    {
        $pulse = app(Pulse::class)->startRecording()->register([
            AssignmentOperations::class => ['enabled' => true],
        ]);
        $officer = User::factory()->role(Role::Officer)->create();

        $this->actingAs($officer)->get('/tasks')->assertOk();
        $pulse->ingest();

        $this->assertDatabaseHas('pulse_entries', [
            'type' => 'assignment_operation',
            'key' => 'tasks.index',
        ]);
        $this->assertGreaterThan(0, DB::table('pulse_entries')
            ->where('type', 'assignment_queries')
            ->where('key', 'tasks.index')
            ->value('value'));
    }

    public function test_overdue_notification_schedule_records_duration_and_failures(): void
    {
        $pulse = app(Pulse::class)->startRecording()->register([
            ScheduledAssignmentTasks::class => ['enabled' => true],
        ]);
        $task = collect(Schedule::events())->firstWhere('description', 'ats:notify-overdue');

        Event::dispatch(new ScheduledTaskFinished($task, 0.25));
        Event::dispatch(new ScheduledTaskFailed($task, new RuntimeException('private detail')));
        $pulse->ingest();

        $this->assertDatabaseHas('pulse_entries', [
            'type' => 'assignment_scheduled',
            'key' => 'ats:notify-overdue',
            'value' => 250,
        ]);
        $this->assertDatabaseHas('pulse_entries', [
            'type' => 'assignment_scheduled_failure',
            'key' => 'ats:notify-overdue',
        ]);
        $this->assertDatabaseMissing('pulse_entries', ['key' => 'private detail']);

        Livewire::withoutLazyLoading()->test(AssignmentOperationsCard::class)
            ->assertSee('Scheduled assignment notifications');
    }

    public function test_outgoing_request_grouping_discards_paths_and_query_tokens(): void
    {
        $groups = config('pulse.recorders.'.SlowOutgoingRequests::class.'.groups');
        $pattern = array_key_first($groups);

        $this->assertSame('api.example.test', preg_replace(
            $pattern,
            $groups[$pattern],
            'https://api.example.test/private/123?token=secret',
        ));
    }
}
