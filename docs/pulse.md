# ATS system monitoring with Laravel Pulse

## Application fit

ATS is an Assignment Tracking System on Laravel 13 and PHP 8.3. Fortify handles
session authentication; Spatie roles and the `User` model determine system
administrator access. Assignments use `Task`, `TaskController`, `TaskService`,
`AssignmentWorkflowService`, and related policies. Creation, listing, progress,
delegation, submission, review, reassignment, unassignment, and evidence routes
are instrumented by route name. Existing diagnostics and audit logs remain in
place. The application has a database queue configured, but no application job
classes at the time of this integration. `ats:notify-overdue` runs as a scheduled
command and email notification delivery is synchronous.

The assignment list and scope code performs multiple database queries and
relationship checks, so the new query count and duration signals should help
identify costly routes. No production baseline was available; these are areas
to measure, not confirmed bottlenecks.

## Signals and privacy

| Signal | Pulse source | Notes |
| --- | --- | --- |
| Assignment rate, duration, failures, query count | ATS Pulse recorder and card | Uses route names only, without task IDs, request bodies, or query strings. |
| Slow requests | Pulse recorder | 500 ms for assignment/evidence routes; 1000 ms elsewhere. |
| Slow SQL | Pulse recorder | SQL without bindings, capped at 2000 characters; auth sessions, password reset tokens, and import payload tables are excluded. |
| Exceptions | Pulse recorder | Class and source location, not exception message. |
| Queue throughput, failures, slow jobs | Pulse recorders | Populates when real asynchronous jobs run. |
| Overdue notification runtime and failures | ATS Pulse recorder and card | Captures the existing `ats:notify-overdue` schedule; it does not appear as a queue job. |
| Cache hit/miss | Pulse recorder | Only known ATS cache namespaces, grouped to remove IDs and hashes; sampled at 25%. |
| Outgoing HTTP | Pulse recorder | Host only; paths and query strings are removed before storage. |
| Request activity | Pulse Usage card | Sampled at 25%; user display is an internal ID only, with no name, email, or Gravatar request. |
| CPU, RAM, disk | Pulse server recorder | Requires `pulse:check` on each application host. |

Pulse data is available at `/pulse` to active system administrators in
administration mode. The `viewPulse` gate also rejects impersonation sessions.
The Pulse route and Livewire updates use the `web`, `auth`, and Pulse
authorization middleware. Review any future hand-written raw SQL for embedded
literals, as SQL text is visible on the slow query card; parameterized SQL does
not store bindings in Pulse.

## Deployment

1. Use a supported production database connection (MySQL, MariaDB, or
   PostgreSQL). This checkout used MySQL for the Pulse migration. Keep
   `PULSE_ENABLED=true`, `PULSE_PATH=pulse`, and `APP_DEBUG=false`. To use a
   separate Pulse database, define that Laravel connection first, then set
   `PULSE_DB_CONNECTION` and run the Pulse migration against it.
2. Run `composer install --no-dev --optimize-autoloader` and
   `php artisan migrate --force`. The published migration creates
   `pulse_values`, `pulse_entries`, and `pulse_aggregates`.
3. Run `php artisan config:cache` after changing the environment. Check that
   an active system administrator can load `/pulse` and that other roles cannot.
4. Run `php artisan pulse:check` continuously on **each application server**.
   Set a unique `PULSE_SERVER_NAME` per host. `PULSE_SERVER_DIRECTORIES` is an
   optional comma-separated list of disk paths; by default the app directory
   is monitored. Under Supervisor, configure one program per host with
   `command=php /path/to/ats/artisan pulse:check`, `autostart=true`, and
   `autorestart=true`. This repository has no existing Supervisor definition.
5. Keep the existing scheduler (`php artisan schedule:run` every minute) and
   queue worker arrangement. Direct database ingest is used; `pulse:work` is
   needed only if Redis ingest is configured later. After deployment, run
   `php artisan pulse:restart` to restart long-lived Pulse checks gracefully.

Pulse automatically trims data outside its window; storage and ingest retention
are set to seven days. Request and cache sampling can be tuned with the
`PULSE_USER_REQUESTS_SAMPLE_RATE` and `PULSE_CACHE_INTERACTIONS_SAMPLE_RATE`
environment variables. Keep exception, queue failure, and slow operation
recorders at full rate unless measured volume calls for a change. The dashboard
is observational; it does not replace alerting or a queue worker supervisor.

For a smoke check, run `php artisan pulse:check --once`, load `/pulse`, make a
normal assignment request, and confirm the Assignment operations card records
its named route. The scheduled overdue command appears in the same card after
it runs. Queue and slow job cards stay empty until asynchronous jobs actually
run. On Windows, Pulse 1.x's bundled server collector uses obsolete
WMIC; ATS uses Pulse's supported collector callbacks and PowerShell CIM instead.
