<?php

namespace App\Services\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\TaskUnassignment;
use Illuminate\Support\Carbon;

class MailRecordPresenter
{
    public function row(MailRecord $mail): array
    {
        [$status, $statusClass] = $this->status($mail);
        $lifecycle = $mail->correspondence?->current_status;
        $activePrimaryRecipients = $mail->correspondence?->recipients
            ?->where('active', true)->where('recipient_type', 'to')->pluck('recipient_name_snapshot')->unique()->values() ?? collect();

        return [
            'id' => $mail->id,
            'direction' => $mail->direction,
            'register_number' => $mail->register_number,
            'sender_name' => $mail->sender_name,
            'recipient_name' => $mail->recipient_name,
            'subject' => $mail->subject,
            'correspondence_reference' => $mail->correspondence_reference,
            'mail_date_label' => $this->date($mail->isIncoming() ? $mail->received_date : $mail->sent_date),
            'activity_date_label' => $this->dateTime($mail->correspondence?->last_activity_at ?? $mail->updated_at),
            'recipient_display' => $activePrimaryRecipients->isNotEmpty()
                ? $activePrimaryRecipients->implode(', ')
                : $mail->recipient_name,
            'status' => $status,
            'status_value' => $mail->status->value,
            'lifecycle_status' => $lifecycle?->value ?? $mail->status->value,
            'status_class' => $statusClass,
            'priority' => $mail->priority->label(),
            'priority_class' => $mail->priority->badgeClass(),
            'financial_year' => $mail->financial_year,
            'department_name' => $mail->department?->name ?? $mail->task?->department?->name,
            'task_reference' => $mail->task?->reference ?? $mail->routingTask?->reference,
            'record_kind' => $mail->direction === 'incoming'
                ? ($lifecycle === CorrespondenceLifecycleStatus::Filed
                    ? 'Incoming · Filed'
                    : ($lifecycle !== null && ! $lifecycle->isActiveIncoming()
                        ? 'Outgoing / Forwarded'
                        : ($mail->archived_at !== null ? 'Incoming · Archived' : 'Incoming')))
                : ($mail->source_mail_record_id !== null ? 'Outgoing · Forwarded' : ($mail->archived_at !== null ? 'Outgoing · Archived' : 'Outgoing')),
            'filed_at_label' => $this->dateTime($mail->correspondence?->filed_at),
            'filed_office' => $mail->correspondence?->filedOrganizationalUnit?->name
                ?? $mail->correspondence?->filedDepartment?->name
                ?? ($lifecycle === CorrespondenceLifecycleStatus::Filed
                    ? ($mail->organizationalUnit?->name ?? $mail->department?->name)
                    : null),
            'filing_category' => $mail->correspondence?->filing_category,
        ];
    }

    public function detail(MailRecord $mail): array
    {
        $mail->loadMissing([
            'correspondence.forwards.recipients.user', 'correspondence.recipients.task', 'correspondence.updates.performedBy', 'correspondence.updates.attachments.uploadedBy', 'correspondence.attachments.uploadedBy',
            'correspondence.filedBy', 'correspondence.filedOrganizationalUnit', 'correspondence.filedDepartment',
            'department', 'task.department', 'task.assignedTo', 'routingTask.department', 'routingTask.assignedTo', 'capturedBy', 'attachments.uploadedBy',
            'officeSupervisor', 'organizationalUnit', 'preparedOnBehalfOf', 'lastProcessedBy',
            'reviewedBy', 'approvedBy', 'sourceMailRecord.attachments.uploadedBy', 'forwardedRecords.routingTask',
        ]);

        $linkedTask = $mail->task ?? $mail->routingTask;
        // A fully unassigned task is released from the mail record but stays
        // reachable through the outgoing forwarding record, so the drawer can
        // still present the withdrawn assignment for accountability.
        $assignmentTask = $linkedTask ?? $mail->forwardedRecords
            ->map(fn (MailRecord $forwarded) => $forwarded->routingTask)
            ->filter()
            ->sortByDesc('id')
            ->first()
            ?? $mail->correspondence?->recipients
                ?->map(fn ($recipient) => $recipient->task)
                ->filter()
                ->sortByDesc('id')
                ->first();
        $attachmentSource = $mail->attachments->isNotEmpty() ? $mail : $mail->sourceMailRecord;
        $correspondenceAttachments = $mail->correspondence?->attachments
            ?->where('status', 'active') ?? collect();
        $recipients = $mail->correspondence?->recipients()
            ->where('active', true)->orderBy('recipient_type')->orderBy('id')->get() ?? collect();

        return [
            ...$this->row($mail),
            'sender_organisation' => $mail->sender_organisation,
            'details' => $mail->details,
            'letter_date_label' => $this->date($mail->letter_date),
            'edit_values' => [
                'sender_name' => $mail->sender_name,
                'sender_organisation' => $mail->sender_organisation ?? '',
                'recipient_name' => $mail->recipient_name,
                'subject' => $mail->subject,
                'details' => $mail->details ?? '',
                'correspondence_reference' => $mail->correspondence_reference ?? '',
                'letter_date' => $mail->letter_date?->toDateString() ?? '',
                'received_date' => $mail->received_date?->toDateString() ?? '',
                'sent_date' => $mail->sent_date?->toDateString() ?? '',
                'receipt_method' => $mail->receipt_method ?? '',
                'confidentiality' => $mail->confidentiality,
                'registry_file_number' => $mail->registry_file_number ?? '',
                'priority' => $mail->priority->value,
            ],
            'receipt_method' => $mail->receipt_method === null ? null : ucfirst($mail->receipt_method),
            'confidentiality' => ucfirst($mail->confidentiality),
            'registry_file_number' => $mail->registry_file_number,
            'captured_by' => $mail->capturedBy?->full_name ?? 'Unknown',
            'captured_at_label' => $mail->created_at?->format('d/m/Y H:i'),
            'office_name' => $mail->organizationalUnit?->name
                ?? $mail->department?->name
                ?? 'Office of the Permanent Secretary',
            'office_supervisor_name' => $mail->officeSupervisor?->full_name,
            'prepared_on_behalf_of' => $mail->preparedOnBehalfOf?->full_name,
            'last_processed_by' => $mail->lastProcessedBy?->full_name,
            'priority' => $mail->priority->label(),
            'priority_class' => $mail->priority->badgeClass(),
            'financial_year' => $mail->financial_year,
            'dispatch_method' => $mail->dispatch_method,
            'dispatch_reference' => $mail->dispatch_reference,
            'dispatched_at_label' => $mail->dispatched_at?->format('d/m/Y H:i'),
            'reviewed_by' => $mail->reviewedBy?->full_name,
            'review_notes' => $mail->review_notes,
            'approved_by' => $mail->approvedBy?->full_name,
            'assigned_to_name' => $linkedTask?->assigned_to_name_snapshot,
            'task_id' => $linkedTask?->id,
            'task_url' => $linkedTask === null ? null : route('tasks.show', $linkedTask),
            'assignment' => $this->assignment($assignmentTask),
            'correspondence_id' => $mail->correspondence_id,
            'correspondence_status' => $mail->correspondence?->current_status?->label() ?? $mail->status->label(),
            'filing' => $mail->correspondence?->filed_at === null ? null : [
                'filed_by' => $mail->correspondence->filedBy?->full_name ?? 'Unknown',
                'filed_at_label' => $this->dateTime($mail->correspondence->filed_at),
                'office' => $mail->correspondence->filedOrganizationalUnit?->name
                    ?? $mail->correspondence->filedDepartment?->name
                    ?? $mail->organizationalUnit?->name
                    ?? $mail->department?->name
                    ?? 'Office not recorded',
                'category' => $mail->correspondence->filing_category,
                'note' => $mail->correspondence->filing_note,
                'is_current' => $mail->correspondence->current_status === CorrespondenceLifecycleStatus::Filed,
            ],
            'primary_recipients' => $recipients->where('recipient_type', 'to')->map(fn ($recipient) => [
                'id' => $recipient->id,
                'name' => $recipient->recipient_name_snapshot,
                'title' => $recipient->recipient_title_snapshot,
                'purpose' => $recipient->purpose,
                'due_date_label' => $this->date($recipient->due_date),
                'task_id' => $recipient->task_id,
            ])->values()->all(),
            'cc_recipients' => $recipients->where('recipient_type', 'cc')->map(fn ($recipient) => [
                'id' => $recipient->id,
                'name' => $recipient->recipient_name_snapshot,
                'title' => $recipient->recipient_title_snapshot,
                'purpose' => 'information',
            ])->values()->all(),
            'source_mail' => $mail->sourceMailRecord === null ? null : [
                'id' => $mail->sourceMailRecord->id,
                'register_number' => $mail->sourceMailRecord->register_number,
                'url' => route('mail.show', $mail->sourceMailRecord),
            ],
            'forwarded_records' => $mail->forwardedRecords->map(fn (MailRecord $forwarded) => [
                'id' => $forwarded->id,
                'register_number' => $forwarded->register_number,
                'task_reference' => $forwarded->routingTask?->reference,
                'url' => route('mail.show', $forwarded),
            ])->values()->all(),
            'attachments_linked_from_source' => $mail->attachments->isEmpty() && $mail->sourceMailRecord?->attachments->isNotEmpty(),
            'attachments' => collect($attachmentSource?->attachments ?? [])->map(fn ($attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type,
                'size_label' => $this->fileSize($attachment->size_bytes),
                'preview_kind' => $attachment->previewKind(),
                'preview_url' => $attachment->previewKind() === 'none' ? null : route('mail.attachments.preview', $attachment),
                'download_url' => route('mail.attachments.download', $attachment),
                'uploaded_by' => $attachment->uploadedBy?->full_name ?? 'Unknown',
                'uploaded_at_label' => $this->dateTime($attachment->uploaded_at),
                'correspondence_attachment_id' => null,
                'version_number' => 1,
            ])->concat($correspondenceAttachments->map(fn (CorrespondenceAttachment $attachment) => [
                'id' => 'correspondence-'.$attachment->id,
                'filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type,
                'size_label' => $this->fileSize($attachment->size_bytes),
                'preview_kind' => $attachment->previewKind(),
                'preview_url' => $attachment->previewKind() === 'none' ? null : route('correspondence.attachments.preview', $attachment),
                'download_url' => route('correspondence.attachments.download', $attachment),
                'uploaded_by' => $attachment->uploadedBy?->full_name ?? 'Unknown',
                'uploaded_at_label' => $this->dateTime($attachment->uploaded_at),
                'correspondence_attachment_id' => $attachment->id,
                'version_number' => $attachment->version_number,
            ]))->values()->all(),
            'activity_history' => $this->activityTimeline($mail),
        ];
    }

    /**
     * The drawer's Assignment Information block: current or withdrawn
     * assignment details plus the immutable unassignment history.
     *
     * @return array<string, mixed>|null
     */
    private function assignment(?Task $task): ?array
    {
        if ($task === null) {
            return null;
        }

        $task->loadMissing(['assignedBy', 'currentAssignee', 'workflowSteps.recipient', 'unassignments']);
        $isWithdrawn = $task->execution_status === 'unassigned';

        $activeAssignees = $task->workflowSteps
            ->where('is_current', true)
            ->filter(fn ($step) => $step->recipient_user_id !== null)
            ->map(fn ($step) => [
                'user_id' => (int) $step->recipient_user_id,
                'name' => $step->recipient?->full_name ?? 'Former / unavailable user',
                'title' => $step->recipient?->title,
                'assigned_at_label' => $this->dateTime($step->assigned_at),
            ])
            ->unique('user_id')
            ->values();
        if ($activeAssignees->isEmpty() && $task->current_assignee_user_id !== null) {
            $activeAssignees->push([
                'user_id' => (int) $task->current_assignee_user_id,
                'name' => $task->currentAssignee?->full_name ?? $task->assigned_to_name_snapshot,
                'title' => $task->currentAssignee?->title,
                'assigned_at_label' => $this->dateTime($task->created_at),
            ]);
        }

        return [
            'task_id' => $task->id,
            'reference' => $task->reference,
            'url' => route('tasks.show', $task),
            'is_withdrawn' => $isWithdrawn,
            'status' => $isWithdrawn ? 'Withdrawn' : $task->workflow_status->label(),
            'status_class' => $isWithdrawn ? 'st-archived' : $task->workflow_status->badgeClass(),
            'execution_status' => str($task->execution_status)->replace('_', ' ')->title()->toString(),
            'progress_percent' => (int) $task->progress_percent,
            'assigned_officer' => $isWithdrawn
                ? 'Unassigned'
                : ($task->currentAssignee?->full_name ?? $task->assigned_to_name_snapshot),
            'active_assignees' => $activeAssignees->all(),
            'assigned_by' => $task->assignedBy?->full_name ?? 'Unknown',
            'priority' => $task->priority->label(),
            'priority_class' => $task->priority->badgeClass(),
            'instructions' => $task->initial_instruction,
            'assigned_at_label' => $this->dateTime($task->created_at),
            'due_date_label' => $task->due_date === null ? null : $this->date($task->due_date),
            'is_overdue' => (bool) $task->overdue,
            'completed_at_label' => $this->dateTime($task->completed_at),
            'unassignments' => $task->unassignments->map(fn (TaskUnassignment $record) => [
                'id' => $record->id,
                'officer' => $record->assigned_user_name_snapshot,
                'unassigned_by' => $record->unassigned_by_name_snapshot,
                'reason' => $record->reason,
                'unassigned_at_label' => $this->dateTime($record->unassigned_at),
                'originally_assigned_at_label' => $this->dateTime($record->original_assignment_at),
            ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activityTimeline(MailRecord $mail): array
    {
        $assignmentMessages = collect([$mail->task, $mail->routingTask])
            ->merge($mail->forwardedRecords->map(fn (MailRecord $forwarded) => $forwarded->routingTask))
            ->merge($mail->correspondence?->recipients?->map(fn ($recipient) => $recipient->task) ?? [])
            ->filter()
            ->unique('id')
            ->flatMap(function (Task $task) use ($mail) {
                $task->loadMissing(['histories.evidence', 'histories.performedBy']);

                return $task->histories
                    ->filter(fn (TaskHistory $entry) => filled($entry->note) && $this->isCommunicationHistory($entry))
                    ->map(fn (TaskHistory $entry) => [
                        'id' => 'task-'.$entry->id,
                        'message' => trim((string) $entry->note),
                        'author_name' => $entry->performed_by_name_snapshot,
                        'author_title' => $entry->performed_by_title_snapshot
                            ?? $entry->performedBy?->officialTitle()
                            ?? $entry->performed_by_role,
                        'author_office' => $entry->performed_by_office_snapshot
                            ?? $entry->performedBy?->officialOfficeName()
                            ?? $this->mailOffice($mail),
                        'recorded_at_label' => $entry->created_at?->format('d/m/Y H:i'),
                        'attachments' => $entry->evidence->map(fn ($attachment) => [
                            'filename' => $attachment->original_filename,
                            'download_url' => route('evidence.download', $attachment),
                        ])->values()->all(),
                        'sort' => $entry->created_at?->getTimestamp() ?? 0,
                        'sequence' => $entry->id,
                        'dedupe' => $this->messageDedupeKey(
                            (string) $entry->note,
                            $entry->performed_by_user_id,
                            $entry->created_at,
                        ),
                    ]);
            });

        $correspondenceMessages = collect($mail->correspondence?->updates ?? [])
            ->filter(fn (CorrespondenceUpdate $entry) => filled($entry->body) && $this->isCommunicationUpdate($entry))
            ->map(function (CorrespondenceUpdate $entry) use ($mail) {
                return [
                    'id' => 'correspondence-'.$entry->id,
                    'message' => trim((string) $entry->body),
                    'author_name' => $entry->performed_by_name_snapshot,
                    'author_title' => $entry->performed_by_title_snapshot
                        ?? $entry->performedBy?->officialTitle()
                        ?? $entry->performed_by_role_snapshot
                        ?? 'Officer',
                    'author_office' => $entry->performed_by_office_snapshot
                        ?? $entry->performedBy?->officialOfficeName()
                        ?? $this->mailOffice($mail),
                    'recorded_at_label' => $entry->created_at?->format('d/m/Y H:i'),
                    'attachments' => $entry->attachments
                        ->where('status', 'active')
                        ->map(fn (CorrespondenceAttachment $attachment) => [
                            'filename' => $attachment->original_filename,
                            'download_url' => route('correspondence.attachments.download', $attachment),
                        ])->values()->all(),
                    'sort' => $entry->created_at?->getTimestamp() ?? 0,
                    'sequence' => $entry->id,
                    'dedupe' => $this->messageDedupeKey(
                        (string) $entry->body,
                        $entry->performed_by_user_id,
                        $entry->created_at,
                    ),
                ];
            });

        return $correspondenceMessages
            ->concat($assignmentMessages)
            ->unique('dedupe')
            ->sortBy([['sort', 'asc'], ['sequence', 'asc']])
            ->values()
            ->map(fn (array $entry) => collect($entry)->except(['sort', 'sequence', 'dedupe'])->all())
            ->all();
    }

    private function isCommunicationUpdate(CorrespondenceUpdate $entry): bool
    {
        return in_array($entry->type, [
            'note',
            'annotation',
            'progress',
            'response',
            'clarification',
            'recommendation',
            'decision',
            'forwarded',
            'assigned',
            'filed',
            'reopened',
        ], true);
    }

    private function isCommunicationHistory(TaskHistory $entry): bool
    {
        return in_array($entry->action_type, [
            'Annotated',
            'Progress Updated',
            'Completed',
            'Submitted for Review',
            'Review Approve',
            'Review Reject',
            'Review Return',
            'Review Request Information',
            'Delegated',
            'Direct Assignment',
        ], true);
    }

    private function messageDedupeKey(string $message, ?int $authorId, ?Carbon $recordedAt): string
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message));

        return implode('|', [$authorId ?? 0, $recordedAt?->format('Y-m-d H:i:s') ?? '', $normalized]);
    }

    private function mailOffice(MailRecord $mail): string
    {
        return $mail->organizationalUnit?->name
            ?? $mail->department?->name
            ?? 'Office not recorded';
    }

    private function dateTime(?Carbon $moment): ?string
    {
        return $moment?->format('d/m/Y H:i');
    }

    private function status(MailRecord $mail): array
    {
        $assignment = $mail->task ?? $mail->routingTask;
        if ($mail->direction === 'outgoing' && $assignment !== null) {
            return [$assignment->workflow_status->label(), $assignment->workflow_status->badgeClass()];
        }

        if ($mail->correspondence?->current_status !== null) {
            $status = $mail->correspondence->current_status;
            $class = match ($status->value) {
                'incoming', 'under_review' => 'st-received',
                'forwarded', 'awaiting_response' => 'info',
                'action_required' => 'st-assigned',
                'responded', 'closed' => 'st-completed',
                'filed', 'withdrawn' => 'st-archived',
                default => 'muted',
            };

            return [$status->label(), $class];
        }

        return [$mail->status->label(), $mail->status->badgeClass()];
    }

    private function date(?Carbon $date): string
    {
        return $date?->format('d/m/Y') ?? '—';
    }

    private function fileSize(int $bytes): string
    {
        return $bytes < 1024 * 1024
            ? number_format($bytes / 1024, 1).' KB'
            : number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
