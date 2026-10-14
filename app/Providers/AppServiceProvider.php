<?php

namespace App\Providers;

use App\Enums\Role;
use App\Livewire\Pulse\AssignmentOperationsCard;
use App\Models\AnnotationTitle;
use App\Models\AssignmentParticipant;
use App\Models\Correspondence;
use App\Models\CorrespondenceAccessGrant;
use App\Models\CorrespondenceForward;
use App\Models\CorrespondenceRecipient;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\Division;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\RecipientAlias;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Models\UserPosition;
use App\Models\Workstream;
use App\Observers\MailRecordObserver;
use App\Pulse\WindowsServerMetrics;
use App\Services\AuditLogger;
use App\Services\DiagnosticRecorder;
use App\Services\ImpersonationService;
use App\Services\Mail\MailModePreferences;
use App\Services\MailSearchReindexDispatcher;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\Servers;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (config('ats.performance.log_slow_queries', false)) {
            DB::listen(function ($query) {
                $request = request();
                $count = (int) $request->attributes->get('perf_query_count', 0) + 1;
                $request->attributes->set('perf_query_count', $count);

                if ($query->time > 200) {
                    Log::info('Performance slow query', [
                        'milliseconds' => round($query->time, 1),
                        'path' => $request->path(),
                        'query_count' => $count,
                        'sql' => mb_substr($query->sql, 0, 200),
                    ]);
                }
            });

            $this->app->terminating(function () {
                $request = request();
                Log::info('Performance request queries', [
                    'path' => $request->path(),
                    'query_count' => (int) $request->attributes->get('perf_query_count', 0),
                ]);
            });
        }

        Gate::define('viewPulse', fn (User $user) => $user->role === Role::Sysadmin
            && $user->mayAuthenticate()
            && request()->hasSession()
            && ! request()->session()->has(ImpersonationService::KEY)
            && request()->session()->get('work_mode', 'administration') === 'administration');

        Pulse::user(fn ($user) => [
            'name' => 'User #'.$user->getAuthIdentifier(),
            'extra' => null,
            'avatar' => null,
        ]);
        Livewire::component('ats.assignment-operations', AssignmentOperationsCard::class);
        if (PHP_OS_FAMILY === 'Windows') {
            Servers::detectCpuUsing(WindowsServerMetrics::cpu(...));
            Servers::detectMemoryUsing(WindowsServerMetrics::memory(...));
        }

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
        $this->maintainMailSearchDocuments();
    }

    private function maintainMailSearchDocuments(): void
    {
        if (! config('ats.search.maintain_mail_search_documents', false)) {
            return;
        }

        MailRecord::observe(MailRecordObserver::class);

        Department::saved(function (Department $department) {
            if ($department->wasRecentlyCreated || $department->wasChanged(['name', 'code'])) {
                app(MailSearchReindexDispatcher::class)->forDepartment($department->id);
            }
        });
        OrganizationalUnit::saved(function (OrganizationalUnit $unit) {
            if ($unit->wasRecentlyCreated || $unit->wasChanged(['name', 'code'])) {
                app(MailSearchReindexDispatcher::class)->forOrganizationalUnit($unit->id);
            }
        });
        AnnotationTitle::saved(function (AnnotationTitle $title) {
            if ($title->wasRecentlyCreated || $title->wasChanged(['shorthand', 'full_title'])) {
                app(MailSearchReindexDispatcher::class)->forAnnotationTitle($title->id);
            }
        });
        User::saved(function (User $user) {
            if ($user->wasChanged(['full_name', 'title'])) {
                app(MailSearchReindexDispatcher::class)->forUser($user->id);
            }
        });
        Task::saved(function (Task $task) {
            if ($task->wasRecentlyCreated || $task->wasChanged(['assigned_to_name_snapshot', 'assigned_to_user_id', 'department_id'])) {
                app(MailSearchReindexDispatcher::class)->forTask($task->id);
            }
        });
        TaskHistory::saved(function (TaskHistory $history) {
            if ($history->wasRecentlyCreated || $history->wasChanged('note')) {
                app(MailSearchReindexDispatcher::class)->forTask($history->task_id);
            }
        });
        TaskHistory::deleted(fn (TaskHistory $history) => app(MailSearchReindexDispatcher::class)->forTask($history->task_id));
    }

    private function invalidateSearchResultsWhenSourceDataChanges(): void
    {
        foreach ([
            Task::class, MailRecord::class, Department::class, Division::class, Workstream::class,
            Correspondence::class, CorrespondenceRecipient::class, CorrespondenceForward::class,
            CorrespondenceAccessGrant::class, AssignmentParticipant::class, OrganizationalUnit::class,
            SecretaryOfficeAttachment::class, UserPosition::class,
        ] as $model) {
            $model::saved(fn () => SearchCache::invalidate());
            $model::deleted(fn () => SearchCache::invalidate());
            if (method_exists($model, 'restored')) {
                $model::restored(fn () => SearchCache::invalidate());
            }
        }

        foreach ([CorrespondenceUpdate::class, AnnotationTitle::class, RecipientAlias::class] as $model) {
            $model::saved(fn () => SearchCache::invalidate());
            $model::deleted(fn () => SearchCache::invalidate());
        }

        User::saved(function (User $user) {
            if ($user->wasChanged(['full_name', 'title', 'active', 'role', 'department_id', 'division_id', 'organizational_unit_id'])) {
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
                app(MailModePreferences::class)->startLogin(request(), $event->user);
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
