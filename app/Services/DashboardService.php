<?php

namespace App\Services;

use App\Enums\AssignmentLevel;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\User;
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
        abort_if($attachment === null, 403, 'You are not currently assigned to support an office.');

        $tasks = $this->secretaryOffices->tasks($user, $attachment);
        $mail = $this->secretaryOffices->applyMail(MailRecord::query(), $user, $attachment);
        $supervisor = $attachment->supervisor;
        $officeName = $supervisor->role === \App\Enums\Role::Ps
            ? 'Office of the Permanent Secretary'
            : ($attachment->organizationalUnit?->name ?? "{$supervisor->title} Office");
        $permissionLabels = $this->secretaryAuthority->availablePermissions();

        return [
            'identity' => [
                'full_name' => $user->full_name,
                'official_job_title' => $attachment->official_job_title,
                'office_name' => $officeName,
                'supervisor_name' => $supervisor->full_name,
                'supervisor_title' => $supervisor->title,
                'starts_at_label' => $attachment->starts_at->format('d/m/Y'),
                'ends_at_label' => $attachment->ends_at?->format('d/m/Y'),
                'delegated_permissions' => collect($attachment->delegated_permissions ?? [])
                    ->map(fn (string $permission) => $permissionLabels[$permission] ?? $permission)
                    ->values()
                    ->all(),
            ],
            'stats' => [
                ...$this->counts(clone $tasks),
                'awaiting_supervisor' => (clone $tasks)
                    ->where(fn (Builder $query) => $query
                        ->where('current_reviewer_user_id', $supervisor->id)
                        ->orWhere('final_approver_user_id', $supervisor->id))
                    ->count(),
                'incoming' => (clone $mail)->where('direction', 'incoming')->count(),
                'outgoing' => (clone $mail)->where('direction', 'outgoing')->count(),
                'drafts' => (clone $mail)->where('direction', 'outgoing')->whereIn('status', ['draft', 'rejected'])->count(),
                'correspondence_awaiting_action' => (clone $mail)->whereIn('status', ['received', 'registered', 'awaiting_review'])->count(),
                'forwarded_assigned' => (clone $mail)->whereIn('status', ['forwarded', 'assigned'])->count(),
                'correspondence_completed' => (clone $mail)->whereIn('status', ['completed', 'archived', 'delivered'])->count(),
            ],
            'follow_ups' => (clone $tasks)
                ->active()
                ->with('department')
                ->orderByRaw('case when due_date is null then 1 else 0 end')
                ->orderBy('due_date')
                ->limit(8)
                ->get()
                ->map(fn (Task $task) => $this->presenter->row($task))
                ->all(),
            'awaiting_supervisor' => (clone $tasks)
                ->where(fn (Builder $query) => $query
                    ->where('current_reviewer_user_id', $supervisor->id)
                    ->orWhere('final_approver_user_id', $supervisor->id))
                ->with('department')
                ->orderBy('due_date')
                ->limit(8)
                ->get()
                ->map(fn (Task $task) => $this->presenter->row($task))
                ->all(),
            'correspondence' => (clone $mail)
                ->with('task.department')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn (MailRecord $record) => $this->mailPresenter->row($record))
                ->all(),
            'schedule' => $attachment->scheduleItems()
                ->where('starts_at', '>=', now()->startOfDay())
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
            'office_notifications' => $user->appNotifications()
                ->orderByDesc('created_at')
                ->limit(6)
                ->get()
                ->map(fn ($notification) => [
                    'id' => $notification->id,
                    'message' => $notification->message,
                    'detail' => $notification->detail,
                    'time_label' => $notification->created_at->format('d/m/Y H:i'),
                    'task_id' => $notification->related_task_id,
                ])
                ->all(),
            'can_create_assignment' => $this->secretaryAuthority->allows($user, 'assignments.create'),
            'can_manage_mail' => $this->secretaryAuthority->allows($user, 'mail.manage'),
        ];
    }

    /** @return array<string, mixed> */
    public function admin(): array
    {
        return [
            'stats' => [
                'total_users' => User::count(),
                'active_users' => User::where('active', true)->count(),
                'departments' => Department::where('active', true)->count(),
                'tasks' => Task::count(),
            ],
            'recent_activity' => AuditLog::orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'text' => $log->action,
                    'who' => $log->actor_name_snapshot,
                    'when_label' => $log->created_at->format('d/m/Y H:i'),
                ])->all(),
            'departments' => Department::where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Department $department) => [
                    'name' => $department->name,
                    'code' => $department->code,
                    'officer_count' => $department->activeOfficerCount(),
                ])->all(),
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
