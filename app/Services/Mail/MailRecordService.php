<?php

namespace App\Services\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Enums\CorrespondenceStatus;
use App\Enums\Role;
use App\Models\AnnotationTitle;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceForward;
use App\Models\CorrespondenceRecipient;
use App\Models\CorrespondenceUpdate;
use App\Models\MailAttachment;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\SecretaryAuthorityService;
use App\Services\Tasks\TaskService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MailRecordService
{
    public function __construct(
        private AuditLogger $audit,
        private TaskService $tasks,
        private NotificationService $notifications,
        private SecretaryAuthorityService $secretaryAuthority,
    ) {}

    /** @param list<UploadedFile> $files */
    public function capture(User $actor, string $direction, array $data, array $files = []): MailRecord
    {
        $storedKeys = [];
        $mail = null;
        $task = null;

        try {
            if ($direction === 'outgoing' && (bool) ($data['requires_follow_up'] ?? false)) {
                $task = $this->tasks->createWithLink(
                    $actor,
                    $this->outgoingTaskData($data),
                    function (Task $createdTask) use ($actor, $direction, $data, $files, &$storedKeys, &$mail): void {
                        $mail = $this->persistCapturedMail($actor, $direction, $data, $files, $createdTask, $storedKeys);
                        $this->persistOutgoingFollowUp($actor, $mail, $createdTask, $data, [], $storedKeys);
                    },
                );
            } else {
                $mail = DB::transaction(function () use ($actor, $direction, $data, $files, &$storedKeys) {
                    return $this->persistCapturedMail(
                        $actor,
                        $direction,
                        $data,
                        $files,
                        null,
                        $storedKeys,
                    );
                });
            }
        } catch (\Throwable $exception) {
            foreach ($storedKeys as $key) {
                Storage::disk('mail')->delete($key);
            }
            throw $exception;
        }

        if (! $mail instanceof MailRecord) {
            throw new \RuntimeException('The correspondence record was not created.');
        }

        $action = $direction === 'incoming'
            ? "Received and registered incoming correspondence {$mail->register_number}"
            : ($mail->status === CorrespondenceStatus::Draft
                ? "Created outgoing correspondence draft {$mail->register_number}"
                : "Created and dispatched outgoing correspondence {$mail->register_number}");
        $this->audit->log('mail', $action, $actor, 'MailRecord', $mail->id, [
            'subject' => $mail->subject,
            'correspondence_reference' => $mail->correspondence_reference,
            'represented_office' => $mail->organizationalUnit?->name,
            'attachments' => count($files),
            'office_supervisor_user_id' => $mail->office_supervisor_user_id,
            'prepared_on_behalf_of_user_id' => $mail->prepared_on_behalf_of_user_id,
            'status' => $mail->status->value,
            'duplicate_override_reason' => $data['duplicate_reason'] ?? null,
            'follow_up_task_id' => $task?->id,
            'cc_user_ids' => array_values($data['cc_user_ids'] ?? []),
        ]);

        if ($task !== null) {
            $this->notifyOutgoingCc($mail, $task);
        }

        return $mail;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $storedKeys
     */
    private function persistCapturedMail(
        User $actor,
        string $direction,
        array $data,
        array $files,
        ?Task $task,
        array &$storedKeys,
    ): MailRecord {
        [$supervisor, $unit] = $this->officeContext($actor);
        [$sourceType, $annotationTitle, $externalSource, $senderName] = $this->incomingSource($direction, $data);
        $status = $data['status'] ?? ($direction === 'incoming'
            ? CorrespondenceStatus::Registered->value
            : (empty($data['sent_date']) ? CorrespondenceStatus::Draft->value : CorrespondenceStatus::Dispatched->value));
        $recordDate = $direction === 'incoming'
            ? ($data['received_date'] ?? now()->toDateString())
            : ($data['sent_date'] ?? $data['letter_date'] ?? now()->toDateString());
        $mail = MailRecord::create([
            'direction' => $direction,
            'register_number' => $this->nullableString($data['register_number'] ?? null)
                ?? $this->nextRegisterNumber($direction),
            'submission_token' => $data['submission_token'] ?? null,
            'sender_name' => $senderName,
            'sender_organisation' => $data['sender_organisation'] ?? null,
            'source_type' => $sourceType,
            'annotation_title_id' => $annotationTitle?->id,
            'external_source' => $externalSource,
            'recipient_name' => trim($data['recipient_name']),
            'subject' => trim($data['subject']),
            'details' => $data['details'] ?? null,
            'correspondence_reference' => $data['correspondence_reference'] ?? null,
            'letter_date' => $data['letter_date'] ?? null,
            'received_date' => $direction === 'incoming' ? $data['received_date'] : null,
            'sent_date' => $direction === 'outgoing' ? $data['sent_date'] : null,
            'receipt_method' => $direction === 'incoming' ? ($data['receipt_method'] ?? null) : null,
            'confidentiality' => $data['confidentiality'],
            'registry_file_number' => $data['registry_file_number'] ?? null,
            'captured_by_user_id' => $actor->id,
            'assigned_by_user_id' => $task === null ? null : $actor->id,
            'assigned_at' => $task === null ? null : now(),
            'task_id' => $task?->id,
            'office_supervisor_user_id' => $supervisor?->id,
            'organizational_unit_id' => $unit?->id,
            // PS Office correspondence must never carry a departmental
            // ownership stamp: a secretary's personal department would
            // otherwise expose it to that department's users (CORR-ACCESS).
            'department_id' => $unit?->department_id
                ?? ($actor->role === Role::Secretary && $supervisor?->role !== Role::Ps
                    ? $actor->department_id
                    : null),
            'prepared_on_behalf_of_user_id' => $direction === 'outgoing' && $actor->role === Role::Secretary
                ? ($supervisor?->id === $actor->id ? null : $supervisor?->id)
                : null,
            'last_processed_by_user_id' => $actor->id,
            'status' => $status,
            'priority' => $data['priority'],
            'financial_year' => $this->financialYear($recordDate),
            'dispatched_at' => $status === CorrespondenceStatus::Dispatched->value
                ? ($data['sent_date'] ?? now())
                : null,
        ]);

        $seenChecksums = [];
        foreach ($files as $file) {
            $checksum = hash_file('sha256', $file->getRealPath());
            if (isset($seenChecksums[$checksum])) {
                throw ValidationException::withMessages(['attachments' => 'The same attachment cannot be added twice.']);
            }
            $seenChecksums[$checksum] = true;

            $key = $file->store((string) $mail->id, ['disk' => 'mail']);
            if ($key === false) {
                throw ValidationException::withMessages(['attachments' => "Upload failed for {$file->getClientOriginalName()}."]);
            }
            $storedKeys[] = $key;

            MailAttachment::create([
                'mail_record_id' => $mail->id,
                'original_filename' => $file->getClientOriginalName(),
                'storage_key' => $key,
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'checksum' => $checksum,
                'uploaded_by_user_id' => $actor->id,
                'uploaded_at' => now(),
            ]);
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    private function outgoingTaskData(array $data, ?MailRecord $mail = null): array
    {
        return [
            'title' => trim((string) ($data['subject'] ?? $mail?->subject)),
            'description' => $data['details'] ?? $mail?->details ?? 'Follow-up action for outgoing correspondence.',
            'assigned_to_user_id' => (int) $data['assigned_to_user_id'],
            'target_type' => 'individual',
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'attachments' => [],
        ];
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $storedKeys
     */
    private function persistOutgoingFollowUp(
        User $actor,
        MailRecord $mail,
        Task $task,
        array $data,
        array $files,
        array &$storedKeys,
    ): void {
        $mail->loadMissing('correspondence');
        $correspondence = $mail->correspondence;
        if ($correspondence === null) {
            throw new \RuntimeException('The outgoing correspondence lifecycle was not created.');
        }

        $forwardedAt = ($mail->sent_date ?? today())->copy()->setTime(now()->hour, now()->minute, now()->second);
        $forward = CorrespondenceForward::create([
            'correspondence_id' => $correspondence->id,
            'forwarded_by_user_id' => $actor->id,
            'on_behalf_of_user_id' => $mail->prepared_on_behalf_of_user_id,
            'from_organizational_unit_id' => $mail->organizational_unit_id,
            'instructions' => trim((string) $data['instructions']),
            'status' => 'sent',
            'forwarded_at' => $forwardedAt,
        ]);

        $primary = User::query()->findOrFail((int) $data['assigned_to_user_id']);
        CorrespondenceRecipient::create([
            'correspondence_id' => $correspondence->id,
            'correspondence_forward_id' => $forward->id,
            'recipient_type' => 'to',
            'purpose' => 'action_required',
            'target_type' => 'individual',
            'user_id' => $primary->id,
            'department_id' => $primary->department_id,
            'recipient_name_snapshot' => $primary->full_name,
            'recipient_title_snapshot' => $primary->title,
            'task_id' => $task->id,
            'due_date' => $data['due_date'] ?? null,
            'active' => true,
            'added_by_user_id' => $actor->id,
            'added_at' => now(),
        ]);

        $ccUsers = User::query()->whereKey($data['cc_user_ids'] ?? [])->get();
        foreach ($ccUsers as $cc) {
            CorrespondenceRecipient::create([
                'correspondence_id' => $correspondence->id,
                'correspondence_forward_id' => $forward->id,
                'recipient_type' => 'cc',
                'purpose' => 'information',
                'target_type' => 'individual',
                'user_id' => $cc->id,
                'department_id' => $cc->department_id,
                'recipient_name_snapshot' => $cc->full_name,
                'recipient_title_snapshot' => $cc->title,
                'active' => true,
                'added_by_user_id' => $actor->id,
                'added_at' => now(),
            ]);
        }

        $recipients = $forward->recipients()->get()->map(fn (CorrespondenceRecipient $recipient) => [
            'type' => $recipient->recipient_type,
            'purpose' => $recipient->purpose,
            'name' => $recipient->recipient_name_snapshot,
        ])->all();
        $update = CorrespondenceUpdate::create([
            'correspondence_id' => $correspondence->id,
            'correspondence_forward_id' => $forward->id,
            'task_id' => $task->id,
            'type' => 'assigned',
            'body' => trim((string) $data['instructions']),
            'status_from' => CorrespondenceLifecycleStatus::Forwarded->value,
            'status_to' => CorrespondenceLifecycleStatus::ActionRequired->value,
            'recipient_summary' => $recipients,
            'performed_by_user_id' => $actor->id,
            'performed_by_name_snapshot' => $actor->full_name,
            'performed_by_title_snapshot' => $actor->title,
            'performed_by_role_snapshot' => $actor->roleName(),
            'created_at' => now(),
        ]);

        foreach ($files as $file) {
            $key = $file->store("correspondence/{$correspondence->id}", ['disk' => 'mail']);
            if ($key === false) {
                throw ValidationException::withMessages(['attachments' => "Upload failed for {$file->getClientOriginalName()}."]);
            }
            $storedKeys[] = $key;
            CorrespondenceAttachment::create([
                'correspondence_id' => $correspondence->id,
                'correspondence_update_id' => $update->id,
                'correspondence_forward_id' => $forward->id,
                'version_group' => (string) Str::uuid(),
                'version_number' => 1,
                'status' => 'active',
                'original_filename' => $file->getClientOriginalName(),
                'storage_key' => $key,
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by_user_id' => $actor->id,
                'uploaded_at' => now(),
            ]);
        }
        $correspondence->update([
            'current_status' => CorrespondenceLifecycleStatus::ActionRequired,
            'last_activity_at' => now(),
            'lock_version' => $correspondence->lock_version + 1,
        ]);
    }

    private function notifyOutgoingCc(MailRecord $mail, Task $task): void
    {
        $mail->loadMissing('correspondence.recipients.user');
        foreach ($mail->correspondence?->recipients->where('recipient_type', 'cc')->where('active', true) ?? [] as $recipient) {
            if ($recipient->user === null) {
                continue;
            }
            $this->notifications->notify(
                $recipient->user,
                'correspondence_cc',
                'CC — outgoing correspondence shared for information',
                'No action is required. This item appears under CC / Information in Correspondence.',
                null,
                $mail,
                "correspondence.outgoing-follow-up.{$task->id}.cc.{$recipient->user->id}",
                'correspondence_updates',
                $mail->confidentiality !== 'normal',
            );
        }
    }

    /**
     * Create a first action assignment for an existing outgoing record while
     * leaving its registry content and source attachments unchanged.
     *
     * @param  list<UploadedFile>  $files
     */
    public function assignOutgoing(User $actor, MailRecord $mail, array $data, array $files = []): Task
    {
        $storedKeys = [];
        $linkedMail = null;

        try {
            $task = $this->tasks->createWithLink(
                $actor,
                $this->outgoingTaskData($data, $mail),
                function (Task $createdTask) use ($actor, $mail, $data, $files, &$storedKeys, &$linkedMail): void {
                    $linkedMail = MailRecord::query()->lockForUpdate()->findOrFail($mail->id);
                    if ($linkedMail->direction !== 'outgoing') {
                        throw ValidationException::withMessages(['mail' => 'Only outgoing correspondence can use this assignment action.']);
                    }
                    $alreadyAssigned = $linkedMail->task_id !== null
                        || $linkedMail->correspondence?->recipients()->whereNotNull('task_id')->exists();
                    if ($alreadyAssigned) {
                        throw ValidationException::withMessages(['mail' => 'This outgoing correspondence already has an assignment. Open its assignment details instead.']);
                    }

                    $linkedMail->update([
                        'task_id' => $createdTask->id,
                        'assigned_by_user_id' => $actor->id,
                        'assigned_at' => now(),
                        'last_processed_by_user_id' => $actor->id,
                    ]);
                    $this->persistOutgoingFollowUp($actor, $linkedMail, $createdTask, $data, $files, $storedKeys);
                },
            );
        } catch (\Throwable $exception) {
            foreach ($storedKeys as $key) {
                Storage::disk('mail')->delete($key);
            }
            throw $exception;
        }

        if (! $linkedMail instanceof MailRecord) {
            throw new \RuntimeException('The outgoing assignment was not linked to its correspondence.');
        }

        $this->audit->log(
            'mail',
            "Created follow-up assignment {$task->reference} for outgoing correspondence {$linkedMail->register_number}",
            $actor,
            'MailRecord',
            $linkedMail->id,
            [
                'task_id' => $task->id,
                'responsible_user_id' => (int) $data['assigned_to_user_id'],
                'cc_user_ids' => array_values($data['cc_user_ids'] ?? []),
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'],
                'attachments' => count($files),
            ],
        );
        $this->notifyOutgoingCc($linkedMail, $task);
        Cache::forget("ats:mail:stats:{$actor->id}");

        return $task;
    }

    public function assign(User $actor, MailRecord $mail, array $data): Task
    {
        $taskData = [
            'title' => $mail->subject,
            'description' => $mail->details ?: "Incoming correspondence from {$mail->sender_name}.",
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'assigned_to_user_ids' => $data['assigned_to_user_ids'] ?? null,
            'target_type' => $data['target_type'] ?? 'individual',
            'organizational_unit_id' => $data['organizational_unit_id'] ?? null,
            'target_department_id' => $data['target_department_id'] ?? null,
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
            'instructions' => $data['instructions'] ?? "Action incoming mail {$mail->register_number}.",
            'workstream_id' => $data['workstream_id'] ?? null,
            'attachments' => $data['attachments'] ?? [],
        ];

        $forwardingRecord = null;
        $task = $this->tasks->createWithLink($actor, $taskData, function (Task $task) use ($actor, $mail, $data, &$forwardingRecord) {
            $locked = MailRecord::query()->lockForUpdate()->findOrFail($mail->id);
            if (! $locked->isIncoming() || $locked->task_id !== null) {
                throw ValidationException::withMessages(['mail' => 'This mail has already been assigned.']);
            }

            $locked->update([
                'task_id' => $task->id,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'status' => CorrespondenceStatus::Assigned,
                'last_processed_by_user_id' => $actor->id,
            ]);

            [$supervisor, $unit] = $this->officeContext($actor);
            $forwardingRecord = MailRecord::create([
                'direction' => 'outgoing',
                'register_number' => $this->nextRegisterNumber('outgoing'),
                'sender_name' => $unit?->name ?? $supervisor?->full_name ?? $actor->full_name,
                'sender_organisation' => config('app.name'),
                'recipient_name' => $task->assigned_to_name_snapshot,
                'subject' => $locked->subject,
                'details' => filled($data['instructions'] ?? null)
                    ? trim((string) $data['instructions'])
                    : "Forwarded for action from incoming correspondence {$locked->register_number}.",
                'correspondence_reference' => $locked->correspondence_reference,
                'letter_date' => $locked->letter_date,
                'sent_date' => now()->toDateString(),
                'confidentiality' => $locked->confidentiality,
                'registry_file_number' => $locked->registry_file_number,
                'captured_by_user_id' => $actor->id,
                'assigned_by_user_id' => $actor->id,
                'source_mail_record_id' => $locked->id,
                'routing_task_id' => $task->id,
                'office_supervisor_user_id' => $supervisor?->id,
                'organizational_unit_id' => $unit?->id,
                'department_id' => $unit?->department_id
                    ?? ($supervisor?->role !== Role::Ps ? $actor->department_id : null),
                'prepared_on_behalf_of_user_id' => $actor->role === Role::Secretary
                    ? ($supervisor?->id === $actor->id ? null : $supervisor?->id)
                    : null,
                'last_processed_by_user_id' => $actor->id,
                'status' => CorrespondenceStatus::Forwarded,
                'priority' => $data['priority'],
                'financial_year' => $this->financialYear(now()),
                'dispatch_method' => 'internal routing',
                'dispatch_reference' => $task->reference,
                'dispatched_at' => now(),
            ]);

            $this->audit->log('mail', "Converted {$locked->register_number} to assignment {$task->reference}", $actor, 'MailRecord', $locked->id, [
                'task_id' => $task->id,
                'department_id' => $task->department_id,
                'assigned_to_user_ids' => $data['assigned_to_user_ids'] ?? array_values(array_filter([$data['assigned_to_user_id'] ?? null])),
                'assignment_target_type' => $task->assignment_target_type,
                'assignment_target' => $task->assigned_to_name_snapshot,
                'forwarding_mail_record_id' => $forwardingRecord->id,
                'status' => CorrespondenceStatus::Assigned->value,
            ]);
            $this->audit->log('mail', "Forwarded {$locked->register_number} as outgoing correspondence {$forwardingRecord->register_number}", $actor, 'MailRecord', $forwardingRecord->id, [
                'source_mail_record_id' => $locked->id,
                'task_id' => $task->id,
                'annotation' => $data['instructions'] ?? null,
                'receiving_target' => $task->assigned_to_name_snapshot,
            ]);
        });

        $officeSupervisor = $mail->officeSupervisor;
        if ($officeSupervisor !== null && $officeSupervisor->id !== $actor->id) {
            $this->notifications->notify(
                $officeSupervisor,
                'correspondence_assigned',
                "{$mail->register_number} was assigned for action",
                "Processed by {$actor->full_name}",
                $task,
                $mail,
                "correspondence.forwarded.{$mail->id}.{$officeSupervisor->id}",
                'correspondence_updates',
                $mail->confidentiality !== 'normal',
            );
        }

        return $task;
    }

    public function updateIncoming(User $actor, MailRecord $mail, array $data): MailRecord
    {
        if (! $mail->isIncoming()) {
            throw ValidationException::withMessages(['mail' => 'Only incoming mail can be edited.']);
        }

        return $this->update($actor, $mail, $data);
    }

    public function update(User $actor, MailRecord $mail, array $data): MailRecord
    {
        $attributes = [
            'sender_name' => trim($data['sender_name']),
            'sender_organisation' => $this->nullableString($data['sender_organisation'] ?? null),
            'recipient_name' => trim($data['recipient_name']),
            'subject' => trim($data['subject']),
            'details' => $this->nullableString($data['details'] ?? null),
            'correspondence_reference' => $this->nullableString($data['correspondence_reference'] ?? null),
            'letter_date' => $data['letter_date'] ?? null,
            'received_date' => $mail->isIncoming() ? ($data['received_date'] ?? null) : null,
            'sent_date' => $mail->isIncoming() ? null : ($data['sent_date'] ?? null),
            'receipt_method' => $mail->isIncoming() ? ($data['receipt_method'] ?? null) : null,
            'confidentiality' => $data['confidentiality'],
            'registry_file_number' => $this->nullableString($data['registry_file_number'] ?? null),
            'priority' => $data['priority'] ?? $mail->priority->value,
            'last_processed_by_user_id' => $actor->id,
        ];

        [$updated, $changes] = DB::transaction(function () use ($mail, $attributes) {
            $locked = MailRecord::query()->lockForUpdate()->findOrFail($mail->id);

            $locked->fill($attributes);
            $dirty = array_intersect_key($locked->getDirty(), $attributes);
            $changes = [];

            foreach ($dirty as $field => $after) {
                $changes[$field] = [
                    'before' => $locked->getRawOriginal($field),
                    'after' => $after,
                ];
            }

            if ($changes !== []) {
                $locked->save();
            }

            return [$locked, $changes];
        });

        if ($changes !== []) {
            $this->audit->log(
                'mail',
                $updated->isIncoming()
                    ? "Edited incoming mail {$updated->register_number}"
                    : "Edited outgoing correspondence {$updated->register_number}",
                $actor,
                'MailRecord',
                $updated->id,
                ['changes' => $changes],
            );
        }

        return $updated;
    }

    public function transition(User $actor, MailRecord $mail, CorrespondenceStatus $status, array $data): MailRecord
    {
        if (! in_array($status, CorrespondenceStatus::forDirection($mail->direction), true)) {
            throw ValidationException::withMessages(['status' => 'That status is not valid for this correspondence direction.']);
        }
        if (in_array($status, [CorrespondenceStatus::Approved, CorrespondenceStatus::Rejected], true)
            && $actor->role !== Role::Ps) {
            throw ValidationException::withMessages(['status' => 'Only the Permanent Secretary may approve or reject correspondence.']);
        }
        if ($status === CorrespondenceStatus::Archived
            && $mail->confidentiality !== 'normal'
            && $actor->role !== Role::Ps) {
            throw ValidationException::withMessages(['status' => 'Sensitive correspondence may only be archived by the Permanent Secretary.']);
        }

        $before = $mail->status;
        $attributes = [
            'status' => $status,
            'last_processed_by_user_id' => $actor->id,
        ];
        if ($status === CorrespondenceStatus::AwaitingReview) {
            $attributes['review_notes'] = $this->nullableString($data['note'] ?? null);
        }
        if (in_array($status, [CorrespondenceStatus::Approved, CorrespondenceStatus::Rejected], true)) {
            $attributes['reviewed_by_user_id'] = $actor->id;
            $attributes['reviewed_at'] = now();
            $attributes['review_notes'] = $this->nullableString($data['note'] ?? null);
            if ($status === CorrespondenceStatus::Approved) {
                $attributes['approved_by_user_id'] = $actor->id;
                $attributes['approved_at'] = now();
            }
        }
        if ($status === CorrespondenceStatus::Dispatched) {
            $attributes['dispatch_method'] = $this->nullableString($data['dispatch_method'] ?? null);
            $attributes['dispatch_reference'] = $this->nullableString($data['dispatch_reference'] ?? null);
            $attributes['dispatched_at'] = $data['dispatched_at'] ?? now();
            $attributes['sent_date'] = substr((string) ($data['dispatched_at'] ?? now()->toDateString()), 0, 10);
        }
        if ($status === CorrespondenceStatus::Archived) {
            $attributes['archived_at'] = now();
        }

        $mail->update($attributes);
        if ($mail->correspondence !== null) {
            $lifecycle = match ($status) {
                CorrespondenceStatus::AwaitingReview => CorrespondenceLifecycleStatus::UnderReview,
                CorrespondenceStatus::Forwarded => CorrespondenceLifecycleStatus::Forwarded,
                CorrespondenceStatus::Assigned => CorrespondenceLifecycleStatus::ActionRequired,
                CorrespondenceStatus::Completed, CorrespondenceStatus::Archived => CorrespondenceLifecycleStatus::Closed,
                default => null,
            };
            if ($lifecycle !== null) {
                $beforeLifecycle = $mail->correspondence->current_status;
                $mail->correspondence->update([
                    'current_status' => $lifecycle,
                    'last_activity_at' => now(),
                    'closed_at' => $lifecycle === CorrespondenceLifecycleStatus::Closed ? now() : null,
                    'lock_version' => $mail->correspondence->lock_version + 1,
                ]);
                CorrespondenceUpdate::create([
                    'correspondence_id' => $mail->correspondence_id,
                    'task_id' => $mail->task_id,
                    'type' => 'status_change',
                    'body' => $data['note'] ?? null,
                    'status_from' => $beforeLifecycle->value,
                    'status_to' => $lifecycle->value,
                    'performed_by_user_id' => $actor->id,
                    'performed_by_name_snapshot' => $actor->full_name,
                    'performed_by_title_snapshot' => $actor->title,
                    'performed_by_role_snapshot' => $actor->roleName(),
                    'created_at' => now(),
                ]);
            }
        }
        $this->audit->log(
            'mail',
            "Changed {$mail->register_number} from {$before->label()} to {$status->label()}",
            $actor,
            'MailRecord',
            $mail->id,
            [
                'before_status' => $before->value,
                'after_status' => $status->value,
                'note' => $data['note'] ?? null,
                'dispatch_method' => $data['dispatch_method'] ?? null,
                'dispatch_reference' => $data['dispatch_reference'] ?? null,
            ],
        );

        $supervisor = $mail->officeSupervisor;
        if ($status === CorrespondenceStatus::AwaitingReview && $supervisor !== null && $supervisor->id !== $actor->id) {
            $this->notifications->notify(
                $supervisor,
                'correspondence_review',
                "{$mail->register_number} is awaiting your review",
                "Submitted by {$actor->full_name}",
            );
        }
        if (in_array($status, [CorrespondenceStatus::Approved, CorrespondenceStatus::Rejected], true)) {
            $secretary = SecretaryOfficeAttachment::current()
                ->where('supervisor_user_id', $actor->id)
                ->with('secretary')
                ->first()?->secretary;
            if ($secretary !== null) {
                $this->notifications->notify(
                    $secretary,
                    'correspondence_reviewed',
                    "{$mail->register_number} was {$status->label()}",
                    $data['note'] ?? "Reviewed by {$actor->full_name}",
                );
            }
        }

        return $mail->refresh();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array{0: ?string, 1: ?AnnotationTitle, 2: ?string, 3: string} */
    private function incomingSource(string $direction, array $data): array
    {
        if ($direction !== 'incoming') {
            return [null, null, null, trim((string) $data['sender_name'])];
        }

        $sourceType = (string) ($data['source_type'] ?? (filled($data['sender_name'] ?? null) ? 'external' : ''));
        if ($sourceType === 'internal') {
            $title = AnnotationTitle::query()
                ->where('active', true)
                ->find($data['annotation_title_id'] ?? null);
            if ($title === null) {
                throw ValidationException::withMessages([
                    'annotation_title_id' => 'Select an active internal source from the shared directory.',
                ]);
            }

            return ['internal', $title, null, "{$title->shorthand} — {$title->full_title}"];
        }

        $externalSource = $this->nullableString($data['external_source'] ?? $data['sender_name'] ?? null);
        if ($sourceType !== 'external' || $externalSource === null) {
            throw ValidationException::withMessages([
                'source_type' => 'Choose one valid incoming correspondence source.',
            ]);
        }

        return ['external', null, $externalSource, $externalSource];
    }

    /** @return array{0: ?User, 1: ?OrganizationalUnit} */
    private function officeContext(User $actor): array
    {
        if ($actor->role === Role::Secretary) {
            $attachment = $this->secretaryAuthority->attachment($actor);
            if ($attachment !== null) {
                return [$attachment->supervisor, $attachment->organizationalUnit];
            }

            if ($actor->department_id !== null) {
                $unit = $actor->currentPositionAssignment()
                    ->with('position.organizationalUnit')
                    ->first()?->position?->organizationalUnit;
                if ($unit?->department_id !== $actor->department_id) {
                    $unit = OrganizationalUnit::query()
                        ->where('department_id', $actor->department_id)
                        ->where('active', true)
                        ->orderByRaw("case when type = 'department' then 0 else 1 end")
                        ->orderBy('id')
                        ->first();
                }

                $supervisor = $actor->supervisor;
                if ($supervisor?->department_id !== $actor->department_id) {
                    $supervisor = $actor->department?->head;
                }

                return [$supervisor ?? $actor, $unit];
            }
        }

        if (! in_array($actor->role, [Role::Ps, Role::Clerk], true) && $actor->department_id !== null) {
            $unit = $actor->currentPositionAssignment()
                ->with('position.organizationalUnit')
                ->first()?->position?->organizationalUnit;
            if ($unit?->department_id !== $actor->department_id) {
                $unit = OrganizationalUnit::query()
                    ->where('department_id', $actor->department_id)
                    ->where('active', true)
                    ->orderByRaw("case when type = 'department' then 0 else 1 end")
                    ->orderBy('id')
                    ->first();
            }

            $supervisor = $actor->department?->head ?? $actor->supervisor ?? $actor;

            return [$supervisor, $unit];
        }

        $supervisor = $actor->role === Role::Ps
            ? $actor
            : User::query()->where('role', Role::Ps->value)->where('active', true)->orderBy('id')->first();
        $unit = OrganizationalUnit::query()
            ->where('name', 'Office of the Permanent Secretary')
            ->where('active', true)
            ->first();

        return [$supervisor, $unit];
    }

    private function financialYear(mixed $date): string
    {
        $parsed = Carbon::parse($date);
        $start = $parsed->month >= 7 ? $parsed->year : $parsed->year - 1;

        return sprintf('%d/%02d', $start, ($start + 1) % 100);
    }

    public function nextRegisterNumber(string $direction): string
    {
        $prefix = $direction === 'incoming' ? 'IM' : 'OM';
        $stem = $prefix.'-'.now()->year.'-';
        $last = MailRecord::withTrashed()
            ->where('register_number', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('register_number')
            ->value('register_number');
        $next = $last === null ? 1 : ((int) substr($last, strlen($stem))) + 1;

        return $stem.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
