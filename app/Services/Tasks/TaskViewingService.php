<?php

namespace App\Services\Tasks;

use App\Models\AssignmentView;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class TaskViewingService
{
    public function __construct(
        private AssignmentTargetService $targets,
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {}

    public function record(User $viewer, Task $task): ?AssignmentView
    {
        $this->notifications->markRelatedTaskRead($viewer, $task);

        if (! $this->isRecipient($viewer, $task) || $task->assigned_by_user_id === $viewer->id) {
            return null;
        }

        [$view, $first] = DB::transaction(function () use ($viewer, $task) {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $view = AssignmentView::query()
                ->where('task_id', $locked->id)
                ->where('user_id', $viewer->id)
                ->first();

            if ($view !== null) {
                $view->update([
                    'latest_viewed_at' => now(),
                    'view_count' => $view->view_count + 1,
                ]);
                $locked->update(['last_viewed_at' => now()]);

                return [$view->refresh(), false];
            }

            $view = AssignmentView::create([
                'task_id' => $locked->id,
                'user_id' => $viewer->id,
                'status_before' => $locked->workflow_status->value,
                'first_viewed_at' => now(),
                'latest_viewed_at' => now(),
                'view_count' => 1,
                'ip_address' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            ]);

            $locked->update([
                'first_viewed_at' => $locked->first_viewed_at ?? $view->first_viewed_at,
                'first_viewed_by_user_id' => $locked->first_viewed_by_user_id ?? $viewer->id,
                'last_viewed_at' => $view->first_viewed_at,
            ]);

            TaskHistory::create([
                'task_id' => $locked->id,
                'action_type' => 'Viewed',
                'note' => "Assignment opened for the first time by {$viewer->full_name}.",
                'status' => $locked->workflow_status->value,
                'progress_percent' => $locked->progress_percent,
                'performed_by_user_id' => $viewer->id,
                'performed_by_name_snapshot' => $viewer->full_name,
                'performed_by_title_snapshot' => $viewer->title,
                'performed_by_role' => substr($viewer->roleName(), 0, 20),
                'created_at' => $view->first_viewed_at,
            ]);

            return [$view, true];
        });

        if ($first) {
            $this->audit->log(
                'task',
                "First viewed {$task->reference}",
                $viewer,
                'Task',
                $task->id,
                [
                    'status_before' => $view->status_before,
                    'first_viewed_at' => $view->first_viewed_at->toIso8601String(),
                ],
            );

            $assigner = $task->assignedBy;
            if ($assigner !== null && $assigner->id !== $viewer->id) {
                $this->notifications->notify(
                    $assigner,
                    'assignment_viewed',
                    "{$viewer->full_name} viewed {$task->reference}",
                    trim(($viewer->title ? "{$viewer->title} · " : '')."{$task->title} · {$view->first_viewed_at->format('d/m/Y H:i')}"),
                    $task,
                    null,
                    "assignment.viewed.{$task->id}.{$viewer->id}",
                    'assignment_views',
                    $task->mailRecord?->confidentiality !== null && $task->mailRecord->confidentiality !== 'normal',
                );
            }
        }

        return $view;
    }

    private function isRecipient(User $viewer, Task $task): bool
    {
        if (in_array($viewer->id, [$task->assigned_to_user_id, $task->current_assignee_user_id, $task->responsible_user_id], true)) {
            return true;
        }

        if ($task->workflowSteps()->where('recipient_user_id', $viewer->id)->exists()) {
            return true;
        }

        return $this->targets->userReceives(
            $viewer,
            $task->assignment_target_type,
            $task->assigned_to_organizational_unit_id,
            $task->assigned_to_department_id,
        );
    }
}
