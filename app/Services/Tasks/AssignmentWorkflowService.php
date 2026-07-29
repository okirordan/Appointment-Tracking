<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Enums\CorrespondenceStatus;
use App\Models\AssignmentParticipant;
use App\Models\AssignmentReview;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentWorkflowStep;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\TaskHistory;
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

            AssignmentParticipant::firstOrCreate([
                'task_id' => $task->id,
                'user_id' => $recipient->id,
                'participant_type' => 'assignee',
            ], ['added_by_user_id' => $actor->id]);

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
            AssignmentParticipant::firstOrCreate(['task_id' => $task->id, 'user_id' => $replacement->id, 'participant_type' => 'assignee'], ['added_by_user_id' => $actor->id]);
            $this->history($task, $actor, 'Reassigned', $reason);
        });

        $this->audit->log('task', "Reassigned {$task->reference} to {$replacement->full_name}", $actor, 'Task', $task->id, ['previous_user_id' => $previous?->id, 'replacement_user_id' => $replacement->id, 'reason' => $reason]);
        $this->notifications->notify($replacement, 'reassignment', "Assignment {$task->reference} reassigned to you", $reason, $task);

        return $current->refresh();
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
