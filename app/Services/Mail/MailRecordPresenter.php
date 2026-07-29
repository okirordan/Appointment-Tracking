<?php

namespace App\Services\Mail;

use App\Models\AuditLog;
use App\Models\MailRecord;
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
            'status_value' => $mail->status->value,
            'status_class' => $statusClass,
            'priority' => $mail->priority->label(),
            'priority_class' => $mail->priority->badgeClass(),
            'financial_year' => $mail->financial_year,
            'department_name' => $mail->task?->department?->name,
            'task_reference' => $mail->task?->reference,
        ];
    }

    public function detail(MailRecord $mail): array
    {
        $mail->loadMissing([
            'task.department', 'task.assignedTo', 'capturedBy', 'attachments.uploadedBy',
            'officeSupervisor', 'organizationalUnit', 'preparedOnBehalfOf', 'lastProcessedBy',
            'reviewedBy', 'approvedBy',
        ]);

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
            'office_name' => $mail->organizationalUnit?->name ?? 'Office of the Permanent Secretary',
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
            'assigned_to_name' => $mail->task?->assignedTo?->full_name,
            'task_id' => $mail->task_id,
            'task_url' => $mail->task === null ? null : route('tasks.show', $mail->task),
            'attachments' => $mail->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type,
                'size_label' => $this->fileSize($attachment->size_bytes),
                'preview_kind' => $attachment->previewKind(),
                'preview_url' => $attachment->previewKind() === 'none' ? null : route('mail.attachments.preview', $attachment),
                'download_url' => route('mail.attachments.download', $attachment),
                'uploaded_by' => $attachment->uploadedBy?->full_name ?? 'Unknown',
            ])->values()->all(),
            'activity_history' => AuditLog::query()
                ->where('category', 'mail')
                ->where('target_type', 'MailRecord')
                ->where('target_id', $mail->id)
                ->latest('created_at')
                ->get()
                ->map(fn (AuditLog $entry) => [
                    'id' => $entry->id,
                    'action' => $entry->action,
                    'performed_by' => $entry->actor_name_snapshot,
                    'performed_at_label' => $entry->created_at?->format('d/m/Y H:i'),
                    'changes' => collect($entry->metadata_json['changes'] ?? [])->map(
                        fn (array $change, string $field) => [
                            'field' => $this->fieldLabel($field),
                            'before' => $this->historyValue($change['before'] ?? null),
                            'after' => $this->historyValue($change['after'] ?? null),
                        ]
                    )->values()->all(),
                ])->values()->all(),
        ];
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
