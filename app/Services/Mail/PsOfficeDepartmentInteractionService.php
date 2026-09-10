<?php

namespace App\Services\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Enums\CorrespondenceStatus;
use App\Models\Correspondence;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceForward;
use App\Models\CorrespondenceRecipient;
use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\Tasks\AssignmentTargetService;
use App\Services\Tasks\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PsOfficeDepartmentInteractionService
{
    private const ENTRY_METHOD = 'ps_cross_department';

    public function __construct(
        private PsOfficeCrossDepartmentAccess $access,
        private TaskService $tasks,
        private AssignmentTargetService $targets,
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     * @return array{update: CorrespondenceUpdate, task: ?Task}
     */
    public function record(User $actor, MailRecord $mail, array $data, array $files = []): array
    {
        if (! $this->access->allows($actor) || ! $actor->can('view', $mail)) {
            throw new AuthorizationException('You are not authorized to record PS Office cross-department movements.');
        }

        $psOffice = $this->access->psOffice();
        $unit = OrganizationalUnit::query()->findOrFail((int) $data['organizational_unit_id']);
        [$from, $to] = $data['direction'] === 'ps_to_department'
            ? [$psOffice, $unit]
            : [$unit, $psOffice];
        $occurredAt = Carbon::parse((string) $data['occurred_at'], config('app.timezone'));
        $duplicate = $this->likelyDuplicate(
            $mail,
            $from,
            $to,
            (string) $data['action_type'],
            $occurredAt,
            $data['annotation'] ?? null,
        );
        if ($duplicate !== null && ! ($data['confirm_duplicate'] ?? false)) {
            throw $this->duplicateException($duplicate);
        }

        $storedKeys = [];
        $update = null;
        try {
            if (filled($data['responsible_user_id'] ?? null)) {
                $task = $this->tasks->createWithLink($actor, [
                    'title' => $mail->subject,
                    'description' => $data['annotation'] ?? $mail->details,
                    'target_type' => 'individual',
                    'assigned_to_user_id' => (int) $data['responsible_user_id'],
                    'priority' => $mail->priority->value,
                    'due_date' => $data['due_date'] ?? null,
                    'instructions' => $data['annotation'] ?? null,
                    'attachments' => [],
                ], function (Task $task) use ($actor, $mail, $data, $files, $from, $to, $occurredAt, &$storedKeys, &$update): void {
                    $update = $this->persist($actor, $mail, $data, $files, $from, $to, $occurredAt, $task, $storedKeys);
                });
            } else {
                $task = null;
                $update = DB::transaction(fn () => $this->persist(
                    $actor,
                    $mail,
                    $data,
                    $files,
                    $from,
                    $to,
                    $occurredAt,
                    null,
                    $storedKeys,
                ));
            }
        } catch (\Throwable $exception) {
            foreach ($storedKeys as $key) {
                Storage::disk('mail')->delete($key);
            }
            throw $exception;
        }

        if (! $update instanceof CorrespondenceUpdate) {
            throw new \RuntimeException('The correspondence movement was not recorded.');
        }

        if ($update->to_organizational_unit_id === $mail->fresh()->correspondence?->current_holder_organizational_unit_id) {
            $this->notifyDestination($actor, $mail, $unit, $to, $update);
        }
        Cache::forget("ats:mail:stats:{$actor->id}");

        return ['update' => $update, 'task' => $task];
    }

    /** @param list<string> $storedKeys */
    private function persist(
        User $actor,
        MailRecord $mail,
        array $data,
        array $files,
        OrganizationalUnit $from,
        OrganizationalUnit $to,
        Carbon $occurredAt,
        ?Task $task,
        array &$storedKeys,
    ): CorrespondenceUpdate {
        $lockedMail = MailRecord::query()->lockForUpdate()->findOrFail($mail->id);
        $correspondence = Correspondence::query()->lockForUpdate()->findOrFail($lockedMail->correspondence_id);
        if (! ($data['confirm_duplicate'] ?? false)) {
            $concurrentDuplicate = $this->likelyDuplicate(
                $lockedMail,
                $from,
                $to,
                (string) $data['action_type'],
                $occurredAt,
                $data['annotation'] ?? null,
            );
            if ($concurrentDuplicate !== null) {
                throw $this->duplicateException($concurrentDuplicate);
            }
        }
        $recordedAt = now();
        $after = CorrespondenceLifecycleStatus::from((string) $data['status_after']);
        $isCurrentMovement = ! $correspondence->updates()
            ->whereNotNull('status_to')
            ->where('occurred_at', '>', $occurredAt)
            ->exists();
        $beforeStatus = $this->statusImmediatelyBefore($correspondence, $occurredAt, $isCurrentMovement);
        $isClosed = in_array($after, [CorrespondenceLifecycleStatus::Closed, CorrespondenceLifecycleStatus::Filed], true);

        if ($isCurrentMovement) {
            $correspondence->recipients()->where('active', true)->where('recipient_type', 'to')->update([
                'active' => false,
                'removed_by_user_id' => $actor->id,
                'removed_at' => $recordedAt,
                'removal_reason' => 'Superseded by a PS Office cross-department movement.',
            ]);
        }

        $forward = CorrespondenceForward::create([
            'correspondence_id' => $correspondence->id,
            'forwarded_by_user_id' => $actor->id,
            'from_organizational_unit_id' => $from->id,
            'instructions' => $data['annotation'] ?? null,
            'status' => 'sent',
            'forwarded_at' => $occurredAt,
        ]);
        $recipient = CorrespondenceRecipient::create([
            'correspondence_id' => $correspondence->id,
            'correspondence_forward_id' => $forward->id,
            'recipient_type' => 'to',
            'purpose' => $after === CorrespondenceLifecycleStatus::ActionRequired ? 'action_required' : 'information',
            'target_type' => 'office',
            'organizational_unit_id' => $to->id,
            'department_id' => $to->department_id,
            'task_id' => $task?->id,
            'recipient_name_snapshot' => $to->name,
            'due_date' => $data['due_date'] ?? null,
            'active' => $isCurrentMovement && ! $isClosed,
            'routing_status' => 'received',
            'added_by_user_id' => $actor->id,
            'added_at' => $recordedAt,
            'received_at' => $occurredAt,
        ]);

        $update = CorrespondenceUpdate::create([
            'correspondence_id' => $correspondence->id,
            'correspondence_forward_id' => $forward->id,
            'task_id' => $task?->id,
            'type' => $data['action_type'],
            'entry_method' => self::ENTRY_METHOD,
            'body' => $data['annotation'] ?? null,
            'from_organizational_unit_id' => $from->id,
            'to_organizational_unit_id' => $to->id,
            'represented_organizational_unit_id' => $from->id,
            'responsible_user_id' => $data['responsible_user_id'] ?? null,
            'status_from' => $beforeStatus,
            'status_to' => $after->value,
            'recipient_summary' => [[
                'type' => 'to',
                'purpose' => $recipient->purpose,
                'name' => $to->name,
            ]],
            'performed_by_user_id' => $actor->id,
            'performed_by_name_snapshot' => $actor->full_name,
            'performed_by_title_snapshot' => $actor->officialTitle(),
            'performed_by_office_snapshot' => $actor->officialOfficeName(),
            'performed_by_role_snapshot' => $actor->roleName(),
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
            'created_at' => $recordedAt,
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
                'uploaded_at' => $recordedAt,
            ]);
        }

        if ($isCurrentMovement) {
            $changes = [
                'current_status' => $after,
                'current_holder_organizational_unit_id' => $isClosed ? null : $to->id,
                'last_activity_at' => $occurredAt,
                'lock_version' => $correspondence->lock_version + 1,
            ];
            if ($after === CorrespondenceLifecycleStatus::Closed) {
                $changes['closed_at'] = $occurredAt;
            }
            if ($after === CorrespondenceLifecycleStatus::Filed) {
                $changes += [
                    'filed_at' => $occurredAt,
                    'filed_by_user_id' => $actor->id,
                    'filed_organizational_unit_id' => $this->access->psOffice()->id,
                    'filing_note' => $data['annotation'] ?? null,
                ];
            }
            $correspondence->update($changes);
            $lockedMail->update([
                'status' => $isClosed ? CorrespondenceStatus::Filed : CorrespondenceStatus::Forwarded,
                'task_id' => $lockedMail->task_id ?? $task?->id,
                'assigned_by_user_id' => $task === null ? $lockedMail->assigned_by_user_id : $actor->id,
                'assigned_at' => $task === null ? $lockedMail->assigned_at : $recordedAt,
                'last_processed_by_user_id' => $actor->id,
            ]);
        }

        $this->audit->log(
            'mail',
            "Recorded {$from->name} to {$to->name} correspondence movement",
            $actor,
            'CorrespondenceUpdate',
            $update->id,
            [
                'master_correspondence_id' => $correspondence->id,
                'mail_record_id' => $lockedMail->id,
                'movement_type' => $data['action_type'],
                'from_organizational_unit_id' => $from->id,
                'to_organizational_unit_id' => $to->id,
                'represented_organizational_unit_id' => $from->id,
                'recorded_by_user_id' => $actor->id,
                'entry_method' => self::ENTRY_METHOD,
                'occurred_at' => $occurredAt->toIso8601String(),
                'recorded_at' => $recordedAt->toIso8601String(),
                'previous_status' => $beforeStatus,
                'new_status' => $after->value,
                'task_id' => $task?->id,
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'attachments' => count($files),
                'duplicate_override' => (bool) ($data['confirm_duplicate'] ?? false),
            ],
        );

        return $update->load(['fromOrganizationalUnit', 'toOrganizationalUnit']);
    }

    private function statusImmediatelyBefore(
        Correspondence $correspondence,
        Carbon $occurredAt,
        bool $isCurrentMovement,
    ): string {
        if ($isCurrentMovement) {
            return $correspondence->current_status->value;
        }

        $precedingStatus = $correspondence->updates()
            ->whereNotNull('status_to')
            ->where('occurred_at', '<=', $occurredAt)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('status_to');
        if (is_string($precedingStatus)) {
            return $precedingStatus;
        }

        $followingStatus = $correspondence->updates()
            ->whereNotNull('status_from')
            ->where('occurred_at', '>', $occurredAt)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->value('status_from');

        return is_string($followingStatus)
            ? $followingStatus
            : $correspondence->current_status->value;
    }

    private function likelyDuplicate(
        MailRecord $mail,
        OrganizationalUnit $from,
        OrganizationalUnit $to,
        string $type,
        Carbon $occurredAt,
        ?string $annotation,
    ): ?CorrespondenceUpdate {
        $normalized = $this->normalizedText($annotation);

        return CorrespondenceUpdate::query()
            ->where('correspondence_id', $mail->correspondence_id)
            ->where('entry_method', self::ENTRY_METHOD)
            ->where('type', $type)
            ->where('from_organizational_unit_id', $from->id)
            ->where('to_organizational_unit_id', $to->id)
            ->whereBetween('occurred_at', [$occurredAt->copy()->subMinutes(30), $occurredAt->copy()->addMinutes(30)])
            ->get()
            ->first(function (CorrespondenceUpdate $candidate) use ($normalized): bool {
                $candidateText = $this->normalizedText($candidate->body);
                if ($normalized === '' || $candidateText === '') {
                    return $normalized === $candidateText;
                }
                similar_text($normalized, $candidateText, $percent);

                return $percent >= 85;
            });
    }

    private function normalizedText(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value));
    }

    private function duplicateException(CorrespondenceUpdate $duplicate): ValidationException
    {
        $when = $duplicate->occurred_at?->format('d/m/Y H:i') ?? 'time not recorded';
        $summary = Str::limit((string) ($duplicate->body ?: 'No annotation'), 120);

        return ValidationException::withMessages([
            'duplicate_confirmation' => "Possible duplicate movement #{$duplicate->id} from {$when}: {$summary}. Review it, then confirm if this is a legitimate repeat.",
        ]);
    }

    private function notifyDestination(
        User $actor,
        MailRecord $mail,
        OrganizationalUnit $departmentUnit,
        OrganizationalUnit $destination,
        CorrespondenceUpdate $update,
    ): void {
        $isReturn = $destination->id === $this->access->psOffice()->id;
        $message = $isReturn
            ? "The Office of the Permanent Secretary recorded this correspondence as returned from {$departmentUnit->name} to the PS Office."
            : "The Office of the Permanent Secretary recorded this correspondence as forwarded to {$departmentUnit->name} for action.";

        $recipients = $destination->type === 'department' && $destination->department_id !== null
            ? $this->targets->departmentMembers($destination->department_id)
            : $this->targets->officeMembers($destination->id);
        foreach ($recipients as $user) {
            if ($user->id === $actor->id) {
                continue;
            }
            $this->notifications->notify(
                $user,
                'ps_cross_department_movement',
                $message,
                'This movement was entered by the PS Office and does not indicate that a departmental user logged in.',
                null,
                $mail,
                "ps-cross-department.{$update->id}.{$user->id}",
                'correspondence_updates',
                $mail->confidentiality !== 'normal',
            );
        }
    }
}
