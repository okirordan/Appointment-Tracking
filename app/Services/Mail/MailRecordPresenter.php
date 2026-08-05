<?php

namespace App\Services\Mail;

use App\Models\AuditLog;
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

        return [
            'id' => $mail->id,
            'direction' => $mail->direction,
            'register_number' => $mail->register_number,
            'sender_name' => $mail->sender_name,
            'recipient_name' => $mail->recipient_name,
            'subject' => $mail->subject,
            'correspondence_reference' => $mail->correspondence_reference,
            'mail_date_label' => $this->date($mail->isIncoming() ? $mail->received_date : $mail->sent_date),
            'status' => $status,
            'status_value' => $mail->status->value,
            'status_class' => $statusClass,
            'priority' => $mail->priority->label(),
            'priority_class' => $mail->priority->badgeClass(),
            'financial_year' => $mail->financial_year,
            'department_name' => $mail->department?->name ?? $mail->task?->department?->name,
            'task_reference' => $mail->task?->reference ?? $mail->routingTask?->reference,
            'record_kind' => $mail->direction === 'incoming'
                ? ($mail->archived_at !== null ? 'Incoming · Archived' : ($mail->task_id !== null ? 'Incoming · Assigned' : 'Incoming'))
                : ($mail->source_mail_record_id !== null ? 'Outgoing · Forwarded' : ($mail->archived_at !== null ? 'Outgoing · Archived' : 'Outgoing')),
        ];
    }

    public function detail(MailRecord $mail): array
    {
        $mail->loadMissing([
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
            ->first();
        $attachmentSource = $mail->attachments->isNotEmpty() ? $mail : $mail->sourceMailRecord;

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
            ])->values()->all(),
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

    /**
     * One chronological trail (latest first) combining the registry audit log
     * with the workflow history of every assignment the mail has produced.
     *
     * @return list<array<string, mixed>>
     */
    private function activityTimeline(MailRecord $mail): array
    {
        $registry = AuditLog::query()
            ->where('category', 'mail')
            ->where('target_type', 'MailRecord')
            ->where('target_id', $mail->id)
            ->latest('created_at')
            ->get()
            ->map(fn (AuditLog $entry) => [
                'id' => 'mail-'.$entry->id,
                'source' => 'Registry',
                'action' => $entry->action,
                'performed_by' => $entry->actor_name_snapshot,
                'performed_at_label' => $entry->created_at?->format('d/m/Y H:i'),
                'note' => null,
                'changes' => collect($entry->metadata_json['changes'] ?? [])->map(
                    fn (array $change, string $field) => [
                        'field' => $this->fieldLabel($field),
                        'before' => $this->historyValue($change['before'] ?? null),
                        'after' => $this->historyValue($change['after'] ?? null),
                    ]
                )->values()->all(),
                'sort' => $entry->created_at?->getTimestamp() ?? 0,
            ]);

        $assignmentEvents = collect([$mail->task, $mail->routingTask])
            ->merge($mail->forwardedRecords->map(fn (MailRecord $forwarded) => $forwarded->routingTask))
            ->filter()
            ->unique('id')
            ->flatMap(function (Task $task) {
                $task->loadMissing('histories');

                return $task->histories->map(fn (TaskHistory $entry) => [
                    'id' => 'task-'.$entry->id,
                    'source' => 'Assignment',
                    'action' => "{$entry->action_type} · {$task->reference}",
                    'performed_by' => $entry->performed_by_name_snapshot,
                    'performed_at_label' => $entry->created_at?->format('d/m/Y H:i'),
                    'note' => $entry->note,
                    'changes' => [],
                    'sort' => $entry->created_at?->getTimestamp() ?? 0,
                ]);
            });

        return $registry->concat($assignmentEvents)
            ->sortByDesc('sort')
            ->values()
            ->map(fn (array $entry) => collect($entry)->except('sort')->all())
            ->all();
    }

    private function dateTime(?Carbon $moment): ?string
    {
        return $moment?->format('d/m/Y H:i');
    }

    private function fieldLabel(string $field): string
    {
        return [
            'sender_name' => 'From',
            'sender_organisation' => 'Organisation / office',
            'recipient_name' => 'Addressed to',
            'subject' => 'Subject',
            'details' => 'Details',
            'correspondence_reference' => 'Correspondence reference',
            'letter_date' => 'Letter date',
            'received_date' => 'Date received',
            'receipt_method' => 'Receipt method',
            'confidentiality' => 'Confidentiality',
            'registry_file_number' => 'Registry file number',
            'priority' => 'Priority',
            'sent_date' => 'Date sent',
        ][$field] ?? str($field)->replace('_', ' ')->title()->toString();
    }

    private function historyValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not provided';
        }

        return (string) $value;
    }

    private function status(MailRecord $mail): array
    {
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
