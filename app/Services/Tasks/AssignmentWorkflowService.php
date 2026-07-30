<?php

namespace App\Services\Tasks;

use App\Enums\CorrespondenceStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\AssignmentParticipant;
use App\Models\AssignmentReview;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentWorkflowStep;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\TaskUnassignment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentWorkflowService
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    public function delegate(User $actor, Task $task, User $recipient, array $data): AssignmentWorkflowStep
    {
        if (! $recipient->active || $recipient->locked || $recipient->trashed() || ! $recipient->isRoleActive()) {
            throw ValidationException::withMessages(['recipient_user_id' => 'The selected recipient is not active.']);
        }

        $current = $task->workflowSteps()->where('is_current', true)->latest('sequence')->first();
        if ($current !== null && $current->recipient_user_id !== $actor->id && ! $actor->can('assignments.reassign')) {
            throw ValidationException::withMessages(['recipient_user_id' => 'Only the current holder may delegate this assignment.']);
        }

        $step = DB::transaction(function () use ($actor, $task, $recipient, $data, $current) {
            if ($current !== null) {
                $current->update(['status' => 'delegated', 'is_current' => false]);
            }

            $sequence = ((int) $task->workflowSteps()->max('sequence')) + 1;
            $step = AssignmentWorkflowStep::create([
                'task_id' => $task->id,
                'sender_user_id' => $actor->id,
                'recipient_user_id' => $recipient->id,
                'position_id' => $recipient->currentPositionAssignment?->position_id,
                'parent_step_id' => $current?->id,
                'sequence' => $sequence,
                'status' => 'active',
                'instructions' => $data['instructions'],
                'assigned_at' => now(),
                'due_at' => $data['due_at'] ?? $task->due_date,
                'is_current' => true,
                'is_direct' => (bool) ($data['is_direct'] ?? false),
            ]);

            $task->update([
                'assigned_to_user_id' => $recipient->id,
                'assigned_to_name_snapshot' => $recipient->full_name,
                'current_assignee_user_id' => $recipient->id,
                'responsible_user_id' => $recipient->id,
                'current_reviewer_user_id' => null,
                'execution_status' => 'delegated',
                'review_status' => 'not_submitted',
                'workflow_status' => TaskStatus::Assigned->value,
                'due_date' => $data['due_at'] ?? $task->due_date,
            ]);

            AssignmentParticipant::updateOrCreate([
                'task_id' => $task->id,
                'user_id' => $recipient->id,
                'participant_type' => 'assignee',
            ], [
                'active' => true,
                'assigned_at' => now(),
                'unassigned_at' => null,
                'added_by_user_id' => $actor->id,
            ]);

            $this->history($task, $actor, ($data['is_direct'] ?? false) ? 'Direct Assignment' : 'Delegated', $data['instructions']);

            return $step;
        });

        $this->audit->log('task', "Delegated {$task->reference} to {$recipient->full_name}", $actor, 'Task', $task->id, [
            'workflow_step_id' => $step->id,
            'parent_step_id' => $step->parent_step_id,
            'direct' => $step->is_direct,
            'due_at' => $step->due_at?->toIso8601String(),
        ]);
        $this->notifications->notify($recipient, 'delegation', "Assignment {$task->reference} delegated to you", $data['instructions'], $task);

        return $step;
    }

    public function submit(User $actor, Task $task, string $note): AssignmentSubmission
    {
        $step = $task->workflowSteps()->where('is_current', true)->where('recipient_user_id', $actor->id)->latest('sequence')->first();
        if ($step === null) {
            throw ValidationException::withMessages(['note' => 'Only the current assignment holder can submit work.']);
        }

        $submission = DB::transaction(function () use ($actor, $task, $step, $note) {
            $step->update(['status' => 'submitted', 'submitted_at' => now(), 'is_current' => false]);
            $submission = AssignmentSubmission::create([
                'task_id' => $task->id,
                'workflow_step_id' => $step->id,
                'submitted_by_user_id' => $actor->id,
                'submitted_by_title_snapshot' => $actor->title,
                'status' => 'pending_review',
                'note' => $note,
                'submitted_at' => now(),
            ]);
            $task->update([
                'current_reviewer_user_id' => $step->sender_user_id,
                'execution_status' => 'submitted',
                'review_status' => 'pending',
                'approval_status' => 'pending',
                'workflow_status' => TaskStatus::AwaitingReview->value,
            ]);
            $this->history($task, $actor, 'Submitted for Review', $note, TaskStatus::AwaitingReview);

            return $submission;
        });

        if ($step->sender !== null) {
            $this->notifications->notify($step->sender, 'review', "{$actor->full_name} submitted {$task->reference} for your review", $note, $task);
        }
        $this->audit->log('task', "Submitted {$task->reference} for review", $actor, 'Task', $task->id, ['workflow_step_id' => $step->id]);

        return $submission;
    }

    public function review(User $actor, AssignmentSubmission $submission, array $data): AssignmentReview
    {
        $submission->loadMissing('task', 'workflowStep.sender', 'workflowStep.recipient', 'workflowStep.parentStep.sender');
        $task = $submission->task;
        $step = $submission->workflowStep;
        if ($submission->status !== 'pending_review') {
            throw ValidationException::withMessages(['decision' => 'This submission has already been reviewed.']);
        }
        if ($step->sender_user_id !== $actor->id && $task->current_reviewer_user_id !== $actor->id && ! $actor->can('assignments.reassign')) {
            throw ValidationException::withMessages(['decision' => 'You are not the current reviewer for this submission.']);
        }

        $decision = $data['decision'];
        $review = DB::transaction(function () use ($actor, $submission, $task, $step, $data, $decision) {
            $review = AssignmentReview::create([
                'submission_id' => $submission->id,
                'workflow_step_id' => $step->id,
                'reviewer_user_id' => $actor->id,
                'reviewer_title_snapshot' => $actor->title,
                'decision' => $decision,
                'comments' => $data['comments'],
                'revised_due_at' => $data['revised_due_at'] ?? null,
                'reviewed_at' => now(),
            ]);
            $submission->update(['status' => $decision]);
            $step->update(['reviewed_at' => now(), 'review_decision' => $decision, 'reviewer_comments' => $data['comments']]);

            if (in_array($decision, ['return', 'request_information'], true)) {
                $step->update(['status' => 'returned', 'is_current' => true]);
                $task->update([
                    'current_assignee_user_id' => $step->recipient_user_id,
                    'current_reviewer_user_id' => null,
                    'execution_status' => 'correction_required',
                    'review_status' => 'returned',
                    'approval_status' => 'pending',
                    'workflow_status' => TaskStatus::InProgress->value,
                    'due_date' => $data['revised_due_at'] ?? $task->due_date,
                ]);
            } elseif ($decision === 'reject') {
                $step->update(['status' => 'rejected']);
                $task->update(['current_reviewer_user_id' => null, 'review_status' => 'rejected', 'approval_status' => 'rejected', 'workflow_status' => TaskStatus::Pending->value]);
            } else {
                $step->update(['status' => 'approved']);
                $parent = $step->parentStep;
                if ($parent !== null) {
                    $parent->update(['status' => 'submitted', 'submitted_at' => now(), 'is_current' => false]);
                    AssignmentSubmission::create([
                        'task_id' => $task->id,
                        'workflow_step_id' => $parent->id,
                        'submitted_by_user_id' => $actor->id,
                        'submitted_by_title_snapshot' => $actor->title,
                        'status' => 'pending_review',
                        'note' => $data['comments'],
                        'submitted_at' => now(),
                    ]);
                    $task->update(['current_reviewer_user_id' => $parent->sender_user_id, 'review_status' => 'pending', 'approval_status' => 'partially_approved', 'workflow_status' => TaskStatus::AwaitingReview->value]);
                } else {
                    $task->update(['current_reviewer_user_id' => null, 'review_status' => 'approved', 'approval_status' => 'approved', 'execution_status' => 'completed', 'workflow_status' => TaskStatus::Completed->value, 'progress_percent' => 100, 'completed_at' => now()]);
                }
            }

            $this->history($task, $actor, 'Review '.str($decision)->replace('_', ' ')->title(), $data['comments'], $task->workflow_status);

            return $review;
        });

        $target = in_array($decision, ['return', 'request_information', 'reject'], true) ? $step->recipient : $step->parentStep?->sender;
        if ($target !== null) {
            $this->notifications->notify($target, 'review', "{$task->reference} was ".str($decision)->replace('_', ' '), $data['comments'], $task);
        }
        $this->audit->log('task', "Review decision {$decision} on {$task->reference}", $actor, 'Task', $task->id, ['submission_id' => $submission->id, 'comments' => $data['comments']]);

        if ($task->workflow_status === TaskStatus::Completed) {
            $mail = MailRecord::where('task_id', $task->id)->first();
            if ($mail !== null) {
                $mail->update([
                    'status' => CorrespondenceStatus::Completed,
                    'last_processed_by_user_id' => $actor->id,
                ]);
                $this->audit->log(
                    'mail',
                    "Approved assignment {$task->reference} completed correspondence {$mail->register_number}",
                    $actor,
                    'MailRecord',
                    $mail->id,
                    ['task_id' => $task->id, 'status' => CorrespondenceStatus::Completed->value],
                );
            }
        }

        return $review;
    }

    public function reassign(User $actor, Task $task, User $replacement, string $reason): AssignmentWorkflowStep
    {
        if (! $replacement->active || $replacement->locked || $replacement->trashed() || ! $replacement->isRoleActive()) {
            throw ValidationException::withMessages(['replacement_user_id' => 'The selected replacement is not active.']);
        }

        $current = $task->workflowSteps()->where('is_current', true)->latest('sequence')->first();
        if ($current === null) {
            $current = AssignmentWorkflowStep::create([
                'task_id' => $task->id,
                'sender_user_id' => $task->assigned_by_user_id,
                'recipient_user_id' => $task->current_assignee_user_id ?? $task->assigned_to_user_id,
                'sequence' => ((int) $task->workflowSteps()->max('sequence')) + 1,
                'status' => 'active',
                'instructions' => $task->initial_instruction,
                'assigned_at' => $task->created_at ?? now(),
                'due_at' => $task->due_date,
                'is_current' => true,
                'is_direct' => true,
            ]);
        }
        $previous = $current->recipient;

        DB::transaction(function () use ($actor, $task, $replacement, $reason, $current) {
            $current->update(['recipient_user_id' => $replacement->id, 'status' => 'reassigned']);
            $task->update(['assigned_to_user_id' => $replacement->id, 'assigned_to_name_snapshot' => $replacement->full_name, 'current_assignee_user_id' => $replacement->id, 'responsible_user_id' => $replacement->id]);
            AssignmentParticipant::updateOrCreate(
                ['task_id' => $task->id, 'user_id' => $replacement->id, 'participant_type' => 'assignee'],
                ['active' => true, 'assigned_at' => now(), 'unassigned_at' => null, 'added_by_user_id' => $actor->id],
            );
            $this->history($task, $actor, 'Reassigned', $reason);
        });

        $this->audit->log('task', "Reassigned {$task->reference} to {$replacement->full_name}", $actor, 'Task', $task->id, ['previous_user_id' => $previous?->id, 'replacement_user_id' => $replacement->id, 'reason' => $reason]);
        $this->notifications->notify($replacement, 'reassignment', "Assignment {$task->reference} reassigned to you", $reason, $task);

        return $current->refresh();
    }

    /**
     * Remove one or more live assignees without deleting their workflow,
     * submissions, annotations, evidence, or participant history.
     *
     * @param  list<int>  $userIds
     * @return list<TaskUnassignment>
     */
    public function unassign(User $actor, Task $task, array $userIds, string $reason, ?string $comments = null): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $comments = blank($comments) ? null : trim((string) $comments);
        $unassignedAt = now();

        [$records, $users, $statusBefore, $statusAfter] = DB::transaction(function () use (
            $actor,
            $task,
            $userIds,
            $reason,
            $comments,
            $unassignedAt,
        ) {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $statusBefore = $lockedTask->workflow_status;
            $currentSteps = $lockedTask->workflowSteps()
                ->where('is_current', true)
                ->whereNotNull('recipient_user_id')
                ->lockForUpdate()
                ->get();

            $activeUserIds = $currentSteps->pluck('recipient_user_id')->map(fn ($id) => (int) $id);
            if ($lockedTask->current_assignee_user_id !== null) {
                $activeUserIds->push((int) $lockedTask->current_assignee_user_id);
            }
            if ($lockedTask->assigned_to_user_id !== null) {
                $activeUserIds->push((int) $lockedTask->assigned_to_user_id);
            }
            $activeUserIds = $activeUserIds->unique()->values();

            $invalid = array_diff($userIds, $activeUserIds->all());
            if ($userIds === [] || $invalid !== []) {
                throw ValidationException::withMessages([
                    'user_ids' => 'Select one or more users who are currently assigned to this task.',
                ]);
            }

            $users = User::withTrashed()->whereKey($userIds)->get()->keyBy('id');
            if ($users->count() !== count($userIds)) {
                throw ValidationException::withMessages(['user_ids' => 'One or more selected users no longer exist.']);
            }

            $isTaskAuthority = $actor->role === Role::Sysadmin
                || in_array($actor->id, [
                    $lockedTask->assigned_by_user_id,
                    $lockedTask->creator_user_id,
                    $lockedTask->owner_user_id,
                ], true);
            $actorLevel = $actor->permissionRole()?->hierarchy_level;
            foreach ($userIds as $userId) {
                $isDirectSender = $currentSteps->contains(
                    fn (AssignmentWorkflowStep $step) => (int) $step->recipient_user_id === $userId
                        && (int) $step->sender_user_id === $actor->id,
                );
                $targetLevel = $users->get($userId)?->permissionRole()?->hierarchy_level;
                $hasHierarchyAuthority = ($actor->can('assignments.reassign')
                        || in_array($actor->role, [Role::Ps, Role::Commissioner], true))
                    && $actorLevel !== null
                    && $targetLevel !== null
                    && $actorLevel < $targetLevel;

                if (! $isTaskAuthority && ! $isDirectSender && ! $hasHierarchyAuthority) {
                    throw ValidationException::withMessages([
                        'user_ids' => 'You are not authorised to unassign one or more of the selected users.',
                    ]);
                }
            }

            $currentSteps->whereIn('recipient_user_id', $userIds)->each(
                fn (AssignmentWorkflowStep $step) => $step->update(['status' => 'unassigned', 'is_current' => false]),
            );
            AssignmentParticipant::query()
                ->where('task_id', $lockedTask->id)
                ->where('participant_type', 'assignee')
                ->whereIn('user_id', $userIds)
                ->update([
                    'active' => false,
                    'unassigned_at' => $unassignedAt,
                    'updated_at' => $unassignedAt,
                ]);

            $remainingStep = $currentSteps
                ->reject(fn (AssignmentWorkflowStep $step) => in_array((int) $step->recipient_user_id, $userIds, true))
                ->sortByDesc('sequence')
                ->first();
            $statusAfter = $remainingStep === null ? TaskStatus::Pending : $statusBefore;

            if ($remainingStep === null) {
                $lockedTask->update([
                    'assigned_to_user_id' => null,
                    'assigned_to_name_snapshot' => 'Unassigned',
                    'current_assignee_user_id' => null,
                    'responsible_user_id' => null,
                    'current_reviewer_user_id' => null,
                    'workflow_status' => $statusAfter,
                    'execution_status' => 'unassigned',
                    'review_status' => 'not_submitted',
                    'approval_status' => 'pending',
                ]);
            } else {
                $remainingUser = $users->get($remainingStep->recipient_user_id)
                    ?? User::withTrashed()->find($remainingStep->recipient_user_id);
                $lockedTask->update([
                    'assigned_to_user_id' => $remainingStep->recipient_user_id,
                    'assigned_to_name_snapshot' => $remainingUser?->full_name ?? 'Assigned officer',
                    'current_assignee_user_id' => $remainingStep->recipient_user_id,
                    'responsible_user_id' => $remainingStep->recipient_user_id,
                ]);
            }

            $records = [];
            foreach ($userIds as $userId) {
                $user = $users->get($userId);
                $step = $currentSteps->firstWhere('recipient_user_id', $userId)
                    ?? $lockedTask->workflowSteps()->where('recipient_user_id', $userId)->latest('sequence')->first();
                $records[] = TaskUnassignment::create([
                    'task_id' => $lockedTask->id,
                    'user_id' => $userId,
                    'assignment_workflow_step_id' => $step?->id,
                    'originally_assigned_by_user_id' => $step?->sender_user_id ?? $lockedTask->assigned_by_user_id,
                    'unassigned_by_user_id' => $actor->id,
                    'task_reference_snapshot' => $lockedTask->reference,
                    'task_title_snapshot' => $lockedTask->title,
                    'assigned_user_name_snapshot' => $user->full_name,
                    'unassigned_by_name_snapshot' => $actor->full_name,
                    'original_assignment_at' => $step?->assigned_at ?? $lockedTask->created_at ?? $unassignedAt,
                    'unassigned_at' => $unassignedAt,
                    'reason' => trim($reason),
                    'comments' => $comments,
                    'status_before' => $statusBefore->value,
                    'status_after' => $statusAfter->value,
                    'created_at' => $unassignedAt,
                ]);
            }

            $names = $users->pluck('full_name')->implode(', ');
            $note = "Unassigned {$names}. Reason: ".trim($reason);
            if ($comments !== null) {
                $note .= " Additional comments: {$comments}";
            }
            $this->history($lockedTask, $actor, 'Unassigned', $note, $statusAfter);

            return [$records, $users, $statusBefore, $statusAfter];
        });

        $this->audit->log(
            'task',
            'Unassigned '.count($records)." user(s) from {$task->reference}",
            $actor,
            'Task',
            $task->id,
            [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'status_before' => $statusBefore->value,
                'status_after' => $statusAfter->value,
                'reason' => trim($reason),
                'comments' => $comments,
                'users' => collect($records)->map(fn (TaskUnassignment $record) => [
                    'user_id' => $record->user_id,
                    'user_name' => $record->assigned_user_name_snapshot,
                    'originally_assigned_by_user_id' => $record->originally_assigned_by_user_id,
                    'original_assignment_at' => $record->original_assignment_at?->toIso8601String(),
                    'unassigned_at' => $record->unassigned_at?->toIso8601String(),
                ])->all(),
            ],
        );

        foreach ($users as $user) {
            $this->notifications->notify(
                $user,
                'task_unassigned',
                "Assignment {$task->reference} has been unassigned from you",
                'Reason: '.trim($reason).($comments === null ? '' : " — {$comments}"),
                $task,
            );
        }

        return $records;
    }

    private function history(Task $task, User $actor, string $action, string $note, ?TaskStatus $status = null): void
    {
        TaskHistory::create([
            'task_id' => $task->id,
            'action_type' => substr($action, 0, 40),
            'note' => $note,
            'status' => ($status ?? $task->workflow_status)->value,
            'progress_percent' => $task->progress_percent,
            'performed_by_user_id' => $actor->id,
            'performed_by_name_snapshot' => $actor->full_name,
            'performed_by_title_snapshot' => $actor->title,
            'performed_by_role' => substr($actor->roleName(), 0, 20),
            'created_at' => now(),
        ]);
    }
}
