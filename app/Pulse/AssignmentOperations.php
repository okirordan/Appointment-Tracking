<?php

namespace App\Pulse;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Pulse\Concerns\ConfiguresAfterResolving;
use Laravel\Pulse\Pulse;
use Symfony\Component\HttpFoundation\Response;

/** Route-level timings and query counts without assignment IDs or payloads. */
class AssignmentOperations
{
    use ConfiguresAfterResolving;

    public function __construct(private Pulse $pulse) {}

    public function register(callable $record, Application $app): void
    {
        Event::listen(QueryExecuted::class, function () use ($app): void {
            if (! $app->bound('request')) {
                return;
            }

            $request = $app->make('request');
            if ($request->routeIs('tasks.*', 'evidence.*')) {
                $request->attributes->set('ats_pulse_queries', $request->attributes->get('ats_pulse_queries', 0) + 1);
            }
        });

        $this->afterResolving($app, Kernel::class, fn (Kernel $kernel) => $kernel->whenRequestLifecycleIsLongerThan(-1, $record));
    }

    public function record(Carbon $startedAt, Request $request, Response $response): void
    {
        if (! $request->routeIs('tasks.*', 'evidence.*')) {
            return;
        }

        $route = $request->route()->getName();
        $duration = (int) $startedAt->diffInMilliseconds();

        $this->pulse->record('assignment_operation', $route, $duration, $startedAt)->count()->avg()->max();
        $this->pulse->record('assignment_queries', $route, (int) $request->attributes->get('ats_pulse_queries', 0), $startedAt)->avg()->max();

        if ($response->getStatusCode() >= 400) {
            $this->pulse->record('assignment_failure', $route, $response->getStatusCode(), $startedAt)->count();
        }
    }
}
