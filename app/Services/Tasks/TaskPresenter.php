<?php

namespace App\Services\Tasks;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

class TaskPresenter
{
    /**
     * Row shape for tables and lists — matches the prototype's taskRow().
     *
     * @return array<string, mixed>
     */
    public function row(Task $task): array
    {
        $overdue = $task->overdue;
        $days = $task->daysOverdue();

        return [
            'id' => $task->id,
            'reference' => $task->reference,
            'title' => $task->title,
            'assigned_to_name' => $task->assigned_to_name_snapshot,
            'department_name' => $task->department?->name ?? 'Central / Office of the PS',
            'priority' => $task->priority->label(),
            'priority_class' => $task->priority->badgeClass(),
            'status' => $task->workflow_status->label(),
            'status_class' => $task->workflow_status->badgeClass(),
            'progress' => $task->progress_percent,
            'progress_class' => $task->workflow_status === TaskStatus::Completed
                ? 'done'
                : ($overdue ? 'late' : ''),
            'due_label' => $this->date($task->due_date),
            'overdue' => $overdue,
            'days_overdue_label' => $overdue ? $days.' day'.($days === 1 ? '' : 's') : '',
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Full detail shape for the task slideover.
     *
     * @return array<string, mixed>
     */
    public function detail(Task $task, ?User $viewer = null): array
    {
        $task->loadMissing([
            'histories', 'evidence.uploadedBy', 'department', 'division', 'workstream', 'assignedBy', 'assignedByDepartment', 'mailRecord.attachments',
            'assignedToOrganizationalUnit', 'assignedToDepartment', 'views.user', 'firstViewedBy', 'forwardingRecord.sourceMailRecord.attachments',
            'correspondenceRecipients.correspondence.originatingMailRecord.attachments',
            'creator', 'owner', 'currentAssignee', 'responsibleOfficer', 'currentReviewer', 'finalApprover',
            'workflowSteps.sender', 'workflowSteps.recipient', 'workflowSteps.position.role', 'submissions.submittedBy', 'submissions.reviews.reviewer',
        ]);

        $pendingSubmission = $task->submissions->where('status', 'pending_review')->sortByDesc('submitted_at')->first();
        $activeAssignees = $task->workflowSteps
            ->where('is_current', true)
            ->filter(fn ($step) => $step->recipient_user_id !== null)
            ->map(fn ($step) => [
                'user_id' => (int) $step->recipient_user_id,
                'name' => $step->recipient?->full_name ?? 'Former / unavailable user',
                'title' => $step->recipient?->title,
                'assigned_at' => $this->dateTime($step->assigned_at),
            ])
            ->unique('user_id')
            ->values();
        if ($activeAssignees->isEmpty() && $task->current_assignee_user_id !== null) {
            $lastStep = $task->workflowSteps
                ->where('recipient_user_id', $task->current_assignee_user_id)
                ->sortByDesc('sequence')
                ->first();
            $activeAssignees->push([
                'user_id' => (int) $task->current_assignee_user_id,
                'name' => $task->currentAssignee?->full_name ?? $task->assigned_to_name_snapshot,
                'title' => $task->currentAssignee?->title,
                'assigned_at' => $this->dateTime($lastStep?->assigned_at ?? $task->created_at),
            ]);
        }

        // After a full unassignment task_id is cleared on the registry row,
        // so the canonical recipient history remains the durable link back
        // to the originating correspondence.
        $mailOrigin = $task->mailRecord
            ?? $task->forwardingRecord?->sourceMailRecord
            ?? $task->correspondenceRecipients
                ->map(fn ($recipient) => $recipient->correspondence?->originatingMailRecord)
                ->filter()
                ->first();

        return [
            ...$this->row($task),
            'description' => $task->description ?? 'No description provided.',
            'status_value' => $task->workflow_status->value,
            'assigned_by_name' => $task->assignedBy?->full_name ?? 'Unknown',
            'assigned_by_role' => $task->assigned_by_role_snapshot,
            'assigned_by_department' => $task->assignedByDepartment?->name,
            'assigned_to_user_id' => $task->assigned_to_user_id,
            'assignment_target_type' => $task->assignment_target_type,
            'assignment_target_label' => $task->assignedToOrganizationalUnit?->name
                ?? $task->assignedToDepartment?->name
                ?? $task->assigned_to_name_snapshot,
            'viewing_status' => $this->viewingStatus($task),
            'first_viewed_at' => $task->first_viewed_at === null ? null : $this->dateTime($task->first_viewed_at),
            'first_viewed_by' => $task->firstViewedBy?->full_name,
            'recipient_views' => $task->views->map(fn ($view) => [
                'user_id' => $view->user_id,
                'name' => $view->user?->full_name ?? 'Former / unavailable user',
                'title' => $view->user?->title,
                'first_viewed_at' => $this->dateTime($view->first_viewed_at),
                'latest_viewed_at' => $this->dateTime($view->latest_viewed_at),
                'view_count' => $view->view_count,
            ])->values()->all(),
            'active_assignees' => $activeAssignees->all(),
            'ownership' => [
                'creator' => $task->creator?->full_name ?? $task->assignedBy?->full_name ?? 'Unknown',
                'owner' => $task->owner?->full_name ?? $task->assignedBy?->full_name ?? 'Unknown',
                'current_assignee' => $task->currentAssignee?->full_name ?? $task->assigned_to_name_snapshot,
                'responsible_officer' => $task->responsibleOfficer?->full_name ?? $task->assigned_to_name_snapshot,
                'current_reviewer' => $task->currentReviewer?->full_name,
                'final_approver' => $task->finalApprover?->full_name,
            ],
            'execution_status' => str($task->execution_status)->replace('_', ' ')->title()->toString(),
            'review_status' => str($task->review_status)->replace('_', ' ')->title()->toString(),
            'approval_status' => str($task->approval_status)->replace('_', ' ')->title()->toString(),
            'workflow_route' => $task->workflowSteps->map(fn ($step) => [
                'id' => $step->id,
                'sequence' => $step->sequence,
                'sender_name' => $step->sender?->full_name ?? 'Former / unavailable user',
                'recipient_name' => $step->recipient?->full_name ?? 'Former / unavailable user',
                'recipient_inactive' => $step->recipient === null || ! $step->recipient->active || $step->recipient->trashed(),
                'position_name' => $step->position?->title,
                'role_name' => $step->position?->role?->label(),
                'status' => str($step->status)->replace('_', ' ')->title()->toString(),
                'status_value' => $step->status,
                'instructions' => $step->instructions,
                'assigned_at' => $this->dateTime($step->assigned_at),
                'due_at' => $this->dateTime($step->due_at),
                'submitted_at' => $this->dateTime($step->submitted_at),
                'reviewed_at' => $this->dateTime($step->reviewed_at),
                'review_decision' => $step->review_decision === null ? null : str($step->review_decision)->replace('_', ' ')->title()->toString(),
                'reviewer_comments' => $step->reviewer_comments,
                'is_skipped' => $step->is_skipped,
                'is_current' => $step->is_current,
                'is_direct' => $step->is_direct,
            ])->values()->all(),
            'pending_submission' => $pendingSubmission === null ? null : [
                'id' => $pendingSubmission->id,
                'note' => $pendingSubmission->note,
                'submitted_by' => $pendingSubmission->submittedBy?->full_name,
                'submitted_by_title' => $pendingSubmission->submitted_by_title_snapshot ?? $pendingSubmission->submittedBy?->title,
                'submitted_at' => $this->dateTime($pendingSubmission->submitted_at),
            ],
            'review_history' => $task->submissions->flatMap(fn ($submission) => $submission->reviews->map(fn ($review) => [
                'id' => $review->id,
                'decision' => str($review->decision)->replace('_', ' ')->title()->toString(),
                'comments' => $review->comments,
                'reviewer' => $review->reviewer?->full_name ?? 'Former / unavailable user',
                'reviewer_title' => $review->reviewer_title_snapshot ?? $review->reviewer?->title,
                'when_label' => $this->dateTime($review->reviewed_at),
            ]))->sortByDesc('id')->values()->all(),
            'initial_instruction' => $task->initial_instruction,
            'division_name' => $task->division?->name,
            'workstream_name' => $task->workstream?->name,
            'mail_origin' => $mailOrigin === null || $viewer?->role === Role::Sysadmin ? null : [
                'register_number' => $mailOrigin->register_number,
                'sender_name' => $mailOrigin->sender_name,
                'recipient_name' => $mailOrigin->recipient_name,
                'correspondence_reference' => $mailOrigin->correspondence_reference,
                'received_date_label' => $this->date($mailOrigin->received_date),
                'details' => $mailOrigin->details,
                'attachment_count' => $mailOrigin->attachments->count(),
                'attachments' => $mailOrigin->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'filename' => $attachment->original_filename,
                    'size_label' => $this->fileSize($attachment->size_bytes),
                    'download_url' => route('mail.attachments.download', $attachment),
                ])->values()->all(),
                // CORR-ACCESS: the original correspondence link is only
                // offered to users the MailRecordPolicy explicitly permits;
                // delegation of the assignment alone is not enough.
                'mail_url' => $viewer?->can('view', $mailOrigin) === true
                    ? route('mail.show', $mailOrigin)
                    : null,
                'forwarding_record_number' => $task->forwardingRecord?->register_number,
                'forwarding_record_url' => $task->forwardingRecord !== null && $viewer?->can('view', $task->forwardingRecord) === true
                    ? route('mail.show', $task->forwardingRecord)
                    : null,
            ],
            'history' => $task->histories
                ->filter(fn ($h) => $h->action_type !== 'Annotated')
                ->map(fn ($h) => [
                    'id' => $h->id,
                    'action_type' => $h->action_type,
                    'status' => $h->status === null ? null : TaskStatus::from($h->status)->label(),
                    'note' => $h->note,
                    'by' => $h->performed_by_name_snapshot,
                    'when_label' => $this->dateTime($h->created_at),
                ])->values()->all(),
            // Oldest first (chronological), with the author's role captured
            // at the moment the annotation was written (CORR-003).
            'annotations' => $task->histories
                ->where('action_type', 'Annotated')
                ->map(fn ($h) => [
                    'id' => $h->id,
                    'author' => $h->performed_by_name_snapshot,
                    'author_role' => $h->performed_by_title_snapshot
                        ?? ($h->performed_by_role === null ? null : (Role::tryFrom($h->performed_by_role)?->label() ?? str($h->performed_by_role)->replace('_', ' ')->title()->toString())),
                    'text' => $h->note,
                    'origin_title' => $h->annotation_origin_snapshot,
                    'recipient_title' => $h->annotation_recipient_snapshot,
                    'when_label' => $this->dateTime($h->created_at),
                ])->values()->all(),
            'evidence' => $task->evidence->map(fn ($e) => [
                'id' => $e->id,
                'filename' => $e->original_filename,
                'source_type' => $e->source_type,
                'mime_type' => $e->mime_type,
                'size_label' => $this->fileSize($e->size_bytes),
                'external_url' => $e->external_url,
                'preview_kind' => $e->previewKind(),
                'preview_url' => $e->previewKind() === 'none' || $e->isLink() ? null : route('evidence.preview', $e),
                'uploaded_by' => $e->uploadedBy?->full_name ?? 'Unknown',
                'when_label' => $this->dateTime($e->uploaded_at),
                'download_url' => route('evidence.download', $e),
            ])->values()->all(),
        ];
    }

    /** Dates display as DD/MM/YYYY (PRD §16.7). */
    public function date(?Carbon $date): string
    {
        return $date?->format('d/m/Y') ?? '—';
    }

    public function dateTime(?Carbon $date): string
    {
        return $date?->format('d/m/Y H:i') ?? '—';
    }

    private function fileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'Link';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / (1024 * 1024), 1).' MB';
    }

    private function viewingStatus(Task $task): string
    {
        if ($task->overdue) {
            return 'Overdue';
        }
        if ($task->workflow_status === TaskStatus::Completed) {
            return 'Completed';
        }
        if ($task->workflowSteps->contains(fn ($step) => $step->status === 'returned')) {
            return 'Returned';
        }
        if ($task->workflowSteps->contains(fn ($step) => $step->status === 'reassigned')) {
            return 'Reassigned';
        }
        if ($task->workflowSteps->contains(fn ($step) => $step->status === 'cancelled')) {
            return 'Cancelled';
        }
        if ($task->workflow_status === TaskStatus::InProgress) {
            return 'In Progress';
        }

        return $task->first_viewed_at === null ? 'Not Viewed' : 'Viewed';
    }
}
