<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\Division;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\User;
use App\Models\Workstream;
use App\Services\AuditLogger;
use App\Services\DiagnosticRecorder;
use App\Services\SearchCache;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(MessageLogged::class, fn ($event) => app(DiagnosticRecorder::class)->message($event));
        foreach ([JobProcessing::class => 'Queue job started', JobProcessed::class => 'Queue job completed', JobFailed::class => 'Queue job failed'] as $event => $label) {
            Event::listen($event, function ($event) use ($label) {
                app(DiagnosticRecorder::class)->record('system', $label, [
                    'connection' => $event->connectionName, 'job' => $event->job->resolveName(), 'job_id' => $event->job->getJobId(),
                    'exception' => $event->exception ?? null,
                ], isset($event->exception) ? 'error' : 'info', isset($event->exception) ? 'failure' : 'success');
            });
        }
        foreach ([ScheduledTaskStarting::class => 'Scheduled task started', ScheduledTaskFinished::class => 'Scheduled task completed', ScheduledTaskFailed::class => 'Scheduled task failed'] as $event => $label) {
            Event::listen($event, fn ($event) => app(DiagnosticRecorder::class)->record('system', $label, [
                'task' => $event->task->description ?: 'Scheduled task', 'exception' => $event->exception ?? null,
            ], isset($event->exception) ? 'error' : 'info', isset($event->exception) ? 'failure' : 'success'));
        }
        $this->auditAuthEvents();
        $this->invalidateSearchResultsWhenSourceDataChanges();
    }

    private function invalidateSearchResultsWhenSourceDataChanges(): void
    {
        foreach ([Task::class, MailRecord::class, Department::class, Division::class, Workstream::class] as $model) {
            $model::saved(fn () => SearchCache::invalidate());
            $model::deleted(fn () => SearchCache::invalidate());
            $model::restored(fn () => SearchCache::invalidate());
        }

        User::saved(function (User $user) {
            if ($user->wasChanged(['full_name', 'title', 'active', 'role', 'department_id', 'division_id'])) {
                SearchCache::invalidate();
            }
        });
        User::deleted(fn () => SearchCache::invalidate());
        User::restored(fn () => SearchCache::invalidate());
    }

    /**
     * AUTH-009: login success/failure, lockouts, and logouts are audited.
     */
    private function auditAuthEvents(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill([
                    'failed_login_count' => 0,
                    'last_login_at' => now(),
                ])->save();
            }

            app(AuditLogger::class)->log('login', 'Signed in', $event->user);
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user !== null) {
                app(AuditLogger::class)->log('login', 'Signed out', $event->user);
            }
        });

        Event::listen(Failed::class, function (Failed $event) {
            $username = (string) ($event->credentials['username'] ?? 'unknown');

            app(AuditLogger::class)->log('login',
                "Failed sign-in attempt for \"{$username}\"",
                $event->user instanceof User ? $event->user : null,
                outcome: 'failure',
                actorName: $username);
        });

        Event::listen(Lockout::class, function (Lockout $event) {
            $username = (string) $event->request->string('username');

            app(AuditLogger::class)->log('security',
                "Login rate limit reached for \"{$username}\"",
                outcome: 'failure',
                actorName: $username);
        });
    }
}
