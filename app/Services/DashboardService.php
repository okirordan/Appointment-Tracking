<?php

namespace App\Services;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\User;
use App\Services\Mail\MailAccessScope;
use App\Services\Mail\MailRecordPresenter;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskScope;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    public function __construct(
        private TaskScope $scope,
        private TaskPresenter $presenter,
        private SecretaryOfficeScope $secretaryOffices,
        private SecretaryAuthorityService $secretaryAuthority,
        private MailRecordPresenter $mailPresenter,
        private MailAccessScope $mailAccess,
    ) {}

    /** @return array<string, mixed> */
    public function executive(User $user): array
    {
        $base = Task::where('assignment_level', AssignmentLevel::Ps->value);

        return [
            'stats' => [
                ...$this->counts(clone $base),
                'awaiting_review' => (clone $base)->where('workflow_status', TaskStatus::AwaitingReview->value)->count(),
            ],
            // DASH-PS-002: most overdue first.
            'stale' => (clone $base)->overdue()
                ->with('department')
                ->orderBy('due_date')
                ->limit(10)
                ->get()
                ->map(fn (Task $task) => $this->presenter->row($task))
                ->all(),
            'department_performance' => Department::where('active', true)
                ->orderBy('name')
                ->get()
                ->map(function (Department $department) use ($base) {
                    $total = (clone $base)->where('department_id', $department->id)->count();
                    $completed = (clone $base)->where('department_id', $department->id)
                        ->whereIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value])->count();
                    $rate = $total === 0 ? 0 : (int) round($completed / $total * 100);

                    return [
                        'id' => $department->id,
                        'name' => $department->name,
                        'completion_label' => "{$completed}/{$total} ({$rate}%)",
                        'rate' => $rate,
                    ];
                })->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function department(User $user): array
    {
        // DASH-DEPT-001: every assignment associated with the department
        // counts, whatever level it was issued at. A PS-level assignment
        // handed to a Commissioner carries the Commissioner's department,
        // so filtering by assignment_level here made the dashboard read
        // zero. Each task is a single row however many times it has been
        // delegated, so nothing is ever counted twice.
        $base = Task::where('department_id', $user->department_id);

        $statusBreakdown = [];
        $total = (clone $base)->count();
        foreach (TaskStatus::cases() as $status) {
            if ($status === TaskStatus::Created || $status === TaskStatus::Archived) {
                continue;
            }
            $count = (clone $base)->where('workflow_status', $status->value)->count();
            $statusBreakdown[] = [
                'label' => $status->label(),
                'count' => $count,
                'pct' => $total === 0 ? 0 : (int) round($count / $total * 100),
            ];
        }

        return [
            'stats' => $this->counts(clone $base),
            'overdue' => (clone $base)->overdue()
                ->with('department')
                ->orderBy('due_date')
                ->limit(8)
                ->get()
                ->map(fn (Task $task) => $this->presenter->row($task))
                ->all(),
            'recent' => (clone $base)->with('department')
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get()
                ->map(fn (Task $task) => $this->presenter->row($task))
                ->all(),
            'status_breakdown' => $statusBreakdown,
        ];
    }

    /** @return array<string, mixed> */
    public function officer(User $user): array
    {
        $base = Task::where('assigned_to_user_id', $user->id);

        return [
            'stats' => $this->counts(clone $base),
            // DASH-OFF-002/003: active tasks due within 7 days, nearest first.
            'upcoming' => (clone $base)->active()
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', today()->addDays(config('ats.upcoming_deadline_days')))
                ->with('department')
                ->orderBy('due_date')
                ->limit(10)
                ->get()
                ->map(fn (Task $task) => $this->presenter->row($task))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function secretaryOffice(User $user): array
    {
        $attachment = $this->secretaryAuthority->attachment($user);
        $departmentId = $this->secretaryAuthority->supportedDepartmentId($user);
        abort_if($attachment === null && $departmentId === null, 403, 'You are not currently assigned to support an office or department.');

        $tasks = $this->secretaryOffices->tasks($user, $attachment);
        $mail = $this->mailAccess->apply(MailRecord::query(), $user);
        $department = $departmentId === null ? null : Department::with('head')->find($departmentId);
        $supervisor = $attachment?->supervisor ?? $department?->head;
        $supervisorId = $supervisor?->id;
        $officeName = $supervisor?->role === Role::Ps
            ? 'Office of the Permanent Secretary'
            : ($attachment?->organizationalUnit?->name
                ?? $department?->name
                ?? (($supervisor?->title ?? 'Supported').' Office'));
        $permissionLabels = $this->secretaryAuthority->availablePermissions();
        $orderedActiveTasks = (clone $tasks)
            ->active()
            ->with(['department', 'assignedBy', 'currentAssignee.department'])
            ->orderByRaw('case when due_date is null then 1 else 0 end')
            ->orderBy('due_date')
            ->orderByDesc('updated_at');
        $activeOfficeTasks = (clone $orderedActiveTasks)->limit(60)->get();
        $isOfficeQueue = function (Task $task) use ($supervisorId, $departmentId): bool {
            if ($supervisorId !== null) {
                return in_array($supervisorId, [
                    $task->assigned_to_user_id,
                    $task->current_assignee_user_id,
                    $task->current_reviewer_user_id,
                    $task->final_approver_user_id,
                ], true);
            }

            return $departmentId !== null && (
                $task->assigned_to_department_id === $departmentId
                || ($task->currentAssignee?->role === Role::Commissioner && $task->currentAssignee?->department_id === $departmentId)
            );
        };
        $assignmentReminders = $activeOfficeTasks
            ->map(function (Task $task) use ($supervisor, $isOfficeQueue): array {
                $assignedOfficer = $task->currentAssignee?->full_name ?? $task->assigned_to_name_snapshot ?? 'Not yet assigned';
                $fromPsOffice = $task->assignment_level === AssignmentLevel::Ps || $task->assignedBy?->role === Role::Ps;
                $waitingForSupervisor = $isOfficeQueue($task);
                $notStarted = $task->first_viewed_at === null || $task->progress_percent === 0;

                if ($waitingForSupervisor && $fromPsOffice) {
                    $message = ($supervisor?->title ?? 'Department').' action required';
                    $detail = "From the PS Office · {$task->reference} · {$task->title}";
                    $kind = 'supervisor';
                } elseif ($waitingForSupervisor) {
                    $message = 'Assignment is waiting for office action';
                    $detail = "{$task->reference} · {$task->title}";
                    $kind = 'supervisor';
                } elseif ($notStarted) {
                    $message = 'Assigned officer has not started this work';
                    $detail = "{$assignedOfficer} · {$task->reference} · {$task->title}";
                    $kind = 'unhandled';
                } else {
                    $message = 'Department assignment remains outstanding';
                    $detail = "{$assignedOfficer} · {$task->reference} · {$task->title} · {$task->progress_percent}% complete";
                    $kind = 'outstanding';
                }

                return [
                    'id' => 'task-'.$task->id,
                    'message' => $message,
                    'detail' => $detail,
                    'time_label' => $task->overdue
                        ? 'Overdue by '.$task->daysOverdue().' day'.($task->daysOverdue() === 1 ? '' : 's')
                        : ($task->due_date?->format('d/m/Y') === null ? 'No due date set' : 'Due '.$task->due_date->format('d/m/Y')),
                    'task_id' => $task->id,
                    'severity' => $task->overdue ? 'urgent' : ($notStarted ? 'warning' : 'info'),
                    'kind' => $kind,
                ];
            })
            ->take(12);
        $activeTaskIds = (clone $tasks)->active()->select('tasks.id');
        $recordedNotificationsQuery = $user->appNotifications()
            ->where(fn (Builder $notification) => $notification
                ->whereNull('related_task_id')
                ->orWhereNotIn('related_task_id', $activeTaskIds));
        $recordedNotificationCount = (clone $recordedNotificationsQuery)->count();
        $recordedNotifications = $recordedNotificationsQuery
            ->orderByDesc('created_at')
            ->limit(12)
            ->get()
            ->map(fn ($notification) => [
                'id' => 'notification-'.$notification->id,
                'message' => $notification->message,
                'detail' => $notification->detail,
                'time_label' => $notification->created_at->format('d/m/Y H:i'),
                'task_id' => $notification->related_task_id,
                'severity' => 'info',
                'kind' => 'notification',
            ]);

        $queue = (clone $tasks)->active();
        if ($supervisorId !== null) {
            $queue->where(fn (Builder $query) => $query
                ->where('assigned_to_user_id', $supervisorId)
                ->orWhere('current_assignee_user_id', $supervisorId)
                ->orWhere('current_reviewer_user_id', $supervisorId)
                ->orWhere('final_approver_user_id', $supervisorId));
        } elseif ($departmentId !== null) {
            $queue->where(fn (Builder $query) => $query
                ->where('assigned_to_department_id', $departmentId)
                ->orWhereHas('currentAssignee', fn (Builder $assignee) => $assignee
                    ->where('role', Role::Commissioner->value)
                    ->where('department_id', $departmentId)));
        } else {
            $queue->whereRaw('1 = 0');
        }

        $followUps = (clone $tasks)->active();
        if ($supervisorId !== null) {
            $followUps->where(function (Builder $query) use ($supervisorId) {
                $query->where(fn (Builder $current) => $current
                    ->whereNotNull('current_assignee_user_id')
                    ->where('current_assignee_user_id', '!=', $supervisorId))
                    ->orWhere(fn (Builder $legacy) => $legacy
                        ->whereNull('current_assignee_user_id')
                        ->where(fn (Builder $assigned) => $assigned
                            ->whereNull('assigned_to_user_id')
                            ->orWhere('assigned_to_user_id', '!=', $supervisorId)));
            });
        } elseif ($departmentId !== null) {
            $followUps->where(fn (Builder $query) => $query
                ->whereHas('currentAssignee', fn (Builder $assignee) => $assignee
                    ->where('role', '!=', Role::Commissioner->value)
                    ->where('department_id', $departmentId))
                ->orWhereHas('assignedTo', fn (Builder $assignee) => $assignee
                    ->where('role', '!=', Role::Commissioner->value)
                    ->where('department_id', $departmentId)));
        }

        $schedule = $this->secretaryOffices->scheduleItems($user, $attachment)
            ->where('starts_at', '>=', now()->startOfDay());
        $scheduleCount = (clone $schedule)->count();
        $followUpCount = (clone $followUps)->count();
        $queueCount = (clone $queue)->count();
        $activeTaskCount = (clone $tasks)->active()->count();
        $correspondenceCount = (clone $mail)->count();

        return [
            'identity' => [
                'full_name' => $user->full_name,
                'official_job_title' => $attachment?->official_job_title ?? $user->title ?? 'Department Secretary',
                'office_name' => $officeName,
                'supervisor_name' => $supervisor?->full_name,
                'supervisor_title' => $supervisor?->title,
                'starts_at_label' => $attachment?->starts_at?->format('d/m/Y'),
                'ends_at_label' => $attachment?->ends_at?->format('d/m/Y'),
                'delegated_permissions' => collect($attachment?->delegated_permissions ?? [])
                    ->map(fn (string $permission) => $permissionLabels[$permission] ?? $permission)
                    ->values()
                    ->all(),
            ],
            'stats' => [
                ...$this->counts(clone $tasks),
                'awaiting_supervisor' => $supervisorId === null ? 0 : (clone $tasks)
                    ->where(fn (Builder $query) => $query
                        ->where('current_reviewer_user_id', $supervisorId)
                        ->orWhere('final_approver_user_id', $supervisorId))
                    ->count(),
                'incoming' => (clone $mail)->where('direction', 'incoming')->count(),
                'outgoing' => (clone $mail)->where('direction', 'outgoing')->count(),
                'drafts' => (clone $mail)->where('direction', 'outgoing')->whereIn('status', ['draft', 'rejected'])->count(),
                'correspondence_awaiting_action' => (clone $mail)->whereIn('status', ['received', 'registered', 'awaiting_review'])->count(),
                'forwarded_assigned' => (clone $mail)->whereIn('status', ['forwarded', 'assigned'])->count(),
                'correspondence_completed' => (clone $mail)->whereIn('status', ['completed', 'archived', 'delivered'])->count(),
            ],
            'section_counts' => [
                'schedule' => $scheduleCount,
                'notifications' => $activeTaskCount + $recordedNotificationCount,
                'correspondence' => $correspondenceCount,
                'follow_ups' => $followUpCount,
                'assignment_queue' => $queueCount,
            ],
            'follow_ups' => $followUps
                ->with(['department', 'assignedBy', 'currentAssignee.department'])
                ->orderByRaw('case when due_date is null then 1 else 0 end')
                ->orderBy('due_date')
                ->limit(8)
                ->get()
                ->map(fn (Task $task) => $this->secretaryTaskRow($task))
                ->all(),
            'assignment_queue' => $queue
                ->with(['department', 'assignedBy', 'currentAssignee.department'])
                ->orderByRaw('case when due_date is null then 1 else 0 end')
                ->orderBy('due_date')
                ->limit(8)
                ->get()
                ->map(fn (Task $task) => $this->secretaryTaskRow($task))
                ->all(),
            'correspondence' => (clone $mail)
                ->with('task.department')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn (MailRecord $record) => $this->mailPresenter->row($record))
                ->all(),
            'schedule' => $schedule
                ->orderBy('starts_at')
                ->limit(10)
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'type' => str($item->type)->replace('_', ' ')->title()->toString(),
                    'title' => $item->title,
                    'notes' => $item->notes,
                    'starts_at_label' => $item->starts_at->format('d/m/Y H:i'),
                    'ends_at_label' => $item->ends_at?->format('d/m/Y H:i'),
                ])
                ->all(),
            'office_notifications' => $assignmentReminders
                ->concat($recordedNotifications)
                ->take(12)
                ->values()
                ->all(),
            'can_create_assignment' => $this->secretaryAuthority->allows($user, 'assignments.create'),
            'can_manage_mail' => $this->secretaryAuthority->allows($user, 'mail.manage'),
            'can_manage_schedule' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function admin(User $viewer, int $activityPage = 1, int $departmentPage = 1): array
    {
        $activity = AuditLog::query()
            ->when($viewer->role === Role::Sysadmin, fn ($query) => $query->where('category', '!=', 'mail'))
            ->orderByDesc('created_at')
            ->paginate(8, ['*'], 'activity_page', max(1, $activityPage));
        $departments = Department::where('active', true)
            ->orderBy('name')
            ->paginate(10, ['*'], 'department_page', max(1, $departmentPage));

        return [
            'stats' => [
                'total_users' => User::count(),
                'active_users' => User::where('active', true)->count(),
                'departments' => Department::where('active', true)->count(),
                'tasks' => Task::count(),
            ],
            'recent_activity' => [
                'data' => collect($activity->items())->map(fn (AuditLog $log) => [
                    'text' => $log->action,
                    'who' => $log->actor_name_snapshot,
                    'when_label' => $log->created_at->format('d/m/Y H:i'),
                ])->all(),
                'meta' => [
                    'current_page' => $activity->currentPage(),
                    'last_page' => $activity->lastPage(),
                    'total' => $activity->total(),
                ],
            ],
            'departments' => [
                'data' => collect($departments->items())->map(fn (Department $department) => [
                    'name' => $department->name,
                    'code' => $department->code,
                    'officer_count' => $department->activeOfficerCount(),
                ])->all(),
                'meta' => [
                    'current_page' => $departments->currentPage(),
                    'last_page' => $departments->lastPage(),
                    'total' => $departments->total(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function secretaryTaskRow(Task $task): array
    {
        return [
            ...$this->presenter->row($task),
            'assigned_by_name' => $task->assignedBy?->full_name ?? 'Unknown',
            'current_assignee_name' => $task->currentAssignee?->full_name ?? $task->assigned_to_name_snapshot,
        ];
    }

    /** @param Builder<Task> $query
     * @return array{total: int, completed: int, overdue: int, active: int} */
    private function counts(Builder $query): array
    {
        // Completed includes Archived: archiving happens after completion,
        // and a finished assignment must not vanish from the completed
        // figure. Soft-deleted records are already excluded by Eloquent.
        // total = active + completed always holds.
        return [
            'total' => (clone $query)->count(),
            'completed' => (clone $query)->whereIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value])->count(),
            'overdue' => (clone $query)->overdue()->count(),
            'active' => (clone $query)->active()->count(),
        ];
    }
}
