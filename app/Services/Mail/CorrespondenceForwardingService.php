<?php

namespace App\Services\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Enums\CorrespondenceStatus;
use App\Enums\Role;
use App\Models\Correspondence;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceForward;
use App\Models\CorrespondenceRecipient;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\Tasks\AssignmentTargetService;
use App\Services\Tasks\TaskService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CorrespondenceForwardingService
{
    public function __construct(
        private TaskService $tasks,
        private AssignmentTargetService $targets,
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     * @return array{forward: CorrespondenceForward, task: ?Task}
     */
    public function forward(User $actor, MailRecord $mail, array $data, array $files = []): array
    {
        $actionRequired = (bool) ($data['action_required'] ?? true);
        $hasInternalPrimary = $this->hasInternalPrimary($data);
        $storedKeys = [];
        $forward = null;

        try {
            if ($actionRequired && $hasInternalPrimary) {
                $taskData = [
                    'title' => $mail->subject,
                    'description' => $mail->details ?: "Correspondence from {$mail->sender_name}.",
                    'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
                    'assigned_to_user_ids' => $data['assigned_to_user_ids'] ?? null,
                    'target_type' => $data['target_type'] ?? 'individual',
                    'organizational_unit_id' => $data['organizational_unit_id'] ?? null,
                    'target_department_id' => $data['target_department_id'] ?? null,
                    'priority' => $data['priority'],
                    'due_date' => $data['due_date'] ?? null,
                    'instructions' => $data['instructions'] ?? null,
                    'workstream_id' => $data['workstream_id'] ?? null,
                    // Forwarding documents belong to the correspondence thread,
                    // so they are not duplicated into task evidence.
                    'attachments' => [],
                ];

                $task = $this->tasks->createWithLink(
                    $actor,
                    $taskData,
                    function (Task $task) use ($actor, $mail, $data, $files, &$storedKeys, &$forward): void {
                        $forward = $this->persist($actor, $mail, $data, $files, true, $task, $storedKeys);
                    },
                );
            } else {
                $task = null;
                $forward = DB::transaction(fn () => $this->persist($actor, $mail, $data, $files, $actionRequired, null, $storedKeys));
            }
        } catch (\Throwable $exception) {
            foreach ($storedKeys as $key) {
                Storage::disk('mail')->delete($key);
            }
            throw $exception;
        }

        if (! $forward instanceof CorrespondenceForward) {
            throw new \RuntimeException('The forwarding record was not created.');
        }

        $this->notifyRecipients($forward, $task);
        Cache::forget("ats:mail:stats:{$actor->id}");

        return ['forward' => $forward, 'task' => $task];
    }

    /** @param list<string> $storedKeys */
    private function persist(
        User $actor,
        MailRecord $mail,
        array $data,
        array $files,
        bool $actionRequired,
        ?Task $task,
        array &$storedKeys,
    ): CorrespondenceForward {
        $locked = MailRecord::query()->lockForUpdate()->findOrFail($mail->id);
        if (! $locked->isIncoming()) {
            throw ValidationException::withMessages(['mail' => 'Only received correspondence can be forwarded from this workflow.']);
        }

        $correspondence = $this->ensureCorrespondence($locked);
        if (in_array($correspondence->current_status, [CorrespondenceLifecycleStatus::Closed, CorrespondenceLifecycleStatus::Withdrawn], true)) {
            throw ValidationException::withMessages(['mail' => 'Closed or withdrawn correspondence must be reopened before it can be forwarded.']);
        }

        $before = $correspondence->current_status;
        $recordedAt = now();
        $forwardedDate = Carbon::createFromFormat(
            'Y-m-d',
            (string) ($data['forwarded_date'] ?? $recordedAt->toDateString()),
            config('app.timezone'),
        )->startOfDay();
        $forwardedAt = $forwardedDate->setTime($recordedAt->hour, $recordedAt->minute, $recordedAt->second);
        $primary = $this->primaryRecipients($data, $task);
        $ccUsers = User::query()->whereKey($data['cc_user_ids'] ?? [])->get();
        foreach ($primary as $recipient) {
            if ($this->alreadyActiveRecipient($correspondence, $recipient)) {
                throw ValidationException::withMessages(['assigned_to_user_ids' => "{$recipient['recipient_name_snapshot']} already has active access to this correspondence."]);
            }
        }
        foreach ($ccUsers as $cc) {
            if ($correspondence->recipients()->where('active', true)->where('user_id', $cc->id)->exists()) {
                throw ValidationException::withMessages(['cc_user_ids' => "{$cc->full_name} already has active access to this correspondence."]);
            }
        }
        $activeExternalNames = $correspondence->recipients()
            ->where('active', true)
            ->where('target_type', 'external')
            ->get(['external_name'])
            ->map(fn (CorrespondenceRecipient $recipient) => str($recipient->external_name)->lower()->trim()->toString());
        foreach ($data['external_recipients'] ?? [] as $external) {
            if ($activeExternalNames->contains(str($external['name'])->lower()->trim()->toString())) {
                throw ValidationException::withMessages(['external_recipients' => "{$external['name']} is already an active external recipient."]);
            }
        }

        $forward = CorrespondenceForward::create([
            'correspondence_id' => $correspondence->id,
            'forwarded_by_user_id' => $actor->id,
            'on_behalf_of_user_id' => $actor->role === Role::Secretary ? $locked->office_supervisor_user_id : null,
            'from_organizational_unit_id' => $locked->organizational_unit_id,
            'instructions' => filled($data['instructions'] ?? null) ? trim((string) $data['instructions']) : null,
            'status' => 'sent',
            'forwarded_at' => $forwardedAt,
        ]);

        foreach ($primary as $recipient) {
            CorrespondenceRecipient::create([
                ...$recipient,
                'correspondence_id' => $correspondence->id,
                'correspondence_forward_id' => $forward->id,
                'recipient_type' => 'to',
                'purpose' => $actionRequired ? 'action_required' : 'information',
                'task_id' => $task?->id,
                'due_date' => $actionRequired ? ($data['due_date'] ?? null) : null,
                'active' => true,
                'added_by_user_id' => $actor->id,
                'added_at' => now(),
            ]);
        }

        foreach ($ccUsers as $cc) {
            CorrespondenceRecipient::create([
                'correspondence_id' => $correspondence->id,
                'correspondence_forward_id' => $forward->id,
                'recipient_type' => 'cc',
                'purpose' => 'information',
                'target_type' => 'individual',
                'user_id' => $cc->id,
                'recipient_name_snapshot' => $cc->full_name,
                'recipient_title_snapshot' => $cc->title,
                'active' => true,
                'added_by_user_id' => $actor->id,
                'added_at' => now(),
            ]);
        }

        foreach ($data['external_recipients'] ?? [] as $external) {
            CorrespondenceRecipient::create([
                'correspondence_id' => $correspondence->id,
                'correspondence_forward_id' => $forward->id,
                'recipient_type' => $external['recipient_type'],
                'purpose' => $external['recipient_type'] === 'to' && $actionRequired ? 'action_required' : 'information',
                'target_type' => 'external',
                'external_name' => trim($external['name']),
                'external_organisation' => filled($external['organisation'] ?? null) ? trim($external['organisation']) : null,
                'recipient_name_snapshot' => trim($external['name']),
                'active' => true,
                'added_by_user_id' => $actor->id,
                'added_at' => now(),
                'due_date' => $external['recipient_type'] === 'to' && $actionRequired ? ($data['due_date'] ?? null) : null,
            ]);
        }

        $after = $actionRequired
            ? CorrespondenceLifecycleStatus::ActionRequired
            : CorrespondenceLifecycleStatus::Forwarded;
        $locked->update([
            'task_id' => $locked->task_id ?? $task?->id,
            'assigned_by_user_id' => $task === null ? $locked->assigned_by_user_id : $actor->id,
            'assigned_at' => $task === null ? $locked->assigned_at : now(),
            'status' => CorrespondenceStatus::Forwarded,
            'last_processed_by_user_id' => $actor->id,
        ]);
        $correspondence->update([
            'current_status' => $after,
            'last_activity_at' => now(),
            'lock_version' => $correspondence->lock_version + 1,
        ]);

        $recipientSummary = $forward->recipients()->get()->map(fn (CorrespondenceRecipient $recipient) => [
            'type' => $recipient->recipient_type,
            'purpose' => $recipient->purpose,
            'name' => $recipient->recipient_name_snapshot,
        ])->all();
        $update = CorrespondenceUpdate::create([
            'correspondence_id' => $correspondence->id,
            'correspondence_forward_id' => $forward->id,
            'task_id' => $task?->id,
            'type' => 'forwarded',
            'body' => $forward->instructions,
            'status_from' => $before->value,
            'status_to' => $after->value,
            'recipient_summary' => $recipientSummary,
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

        $this->audit->log('mail', "Forwarded correspondence {$locked->register_number}", $actor, 'MailRecord', $locked->id, [
            'correspondence_id' => $correspondence->id,
            'correspondence_forward_id' => $forward->id,
            'task_id' => $task?->id,
            'before_status' => $before->value,
            'after_status' => $after->value,
            'forwarded_date' => $forwardedAt->toDateString(),
            'recipients' => $recipientSummary,
            'attachments' => count($files),
        ]);

        return $forward->load('recipients.user');
    }

    private function ensureCorrespondence(MailRecord $mail): Correspondence
    {
        if ($mail->correspondence_id !== null) {
            return Correspondence::query()->lockForUpdate()->findOrFail($mail->correspondence_id);
        }

        $correspondence = Correspondence::create([
            'canonical_reference' => $mail->register_number,
            'origin_direction' => $mail->direction,
            'originating_mail_record_id' => $mail->id,
            'office_supervisor_user_id' => $mail->office_supervisor_user_id,
            'organizational_unit_id' => $mail->organizational_unit_id,
            'department_id' => $mail->department_id,
            'confidentiality' => $mail->confidentiality,
            'current_status' => CorrespondenceLifecycleStatus::Incoming,
            'last_activity_at' => $mail->updated_at ?? now(),
        ]);
        $mail->update(['correspondence_id' => $correspondence->id]);

        return $correspondence;
    }

    /** @return list<array<string, mixed>> */
    private function primaryRecipients(array $data, ?Task $task): array
    {
        $type = $data['target_type'] ?? 'individual';
        if ($type === 'office') {
            $office = OrganizationalUnit::findOrFail($data['organizational_unit_id']);

            return [[
                'target_type' => 'office', 'organizational_unit_id' => $office->id,
                'department_id' => $office->department_id, 'recipient_name_snapshot' => $office->name,
            ]];
        }
        if ($type === 'department') {
            $department = Department::findOrFail($data['target_department_id']);

            return [[
                'target_type' => 'department', 'department_id' => $department->id,
                'recipient_name_snapshot' => $department->name,
            ]];
        }

        $ids = collect($data['assigned_to_user_ids'] ?? [])
            ->when(isset($data['assigned_to_user_id']), fn ($values) => $values->push((int) $data['assigned_to_user_id']))
            ->map(fn ($id) => (int) $id)->filter()->unique()->values();

        return User::query()->whereKey($ids)->get()->map(fn (User $user) => [
            'target_type' => 'individual', 'user_id' => $user->id,
            'department_id' => $user->department_id,
            'recipient_name_snapshot' => $user->full_name,
            'recipient_title_snapshot' => $user->title,
        ])->values()->all();
    }

    private function hasInternalPrimary(array $data): bool
    {
        return filled($data['organizational_unit_id'] ?? null)
            || filled($data['target_department_id'] ?? null)
            || filled($data['assigned_to_user_id'] ?? null)
            || collect($data['assigned_to_user_ids'] ?? [])->filter()->isNotEmpty();
    }

    private function notifyRecipients(CorrespondenceForward $forward, ?Task $task): void
    {
        $forward->loadMissing('recipients.user', 'correspondence.mailRecords');
        $mail = $forward->correspondence->mailRecords->firstWhere('id', $forward->correspondence->originating_mail_record_id)
            ?? $forward->correspondence->mailRecords->first();
        $seen = [];

        foreach ($forward->recipients as $recipient) {
            $users = match ($recipient->target_type) {
                'office' => $recipient->organizational_unit_id === null ? collect() : $this->targets->officeMembers($recipient->organizational_unit_id),
                'department' => $recipient->department_id === null ? collect() : $this->targets->departmentMembers($recipient->department_id),
                default => collect([$recipient->user])->filter(),
            };

            foreach ($users as $user) {
                $key = "{$recipient->recipient_type}:{$user->id}";
                if (isset($seen[$key]) || ($task !== null && $recipient->purpose === 'action_required')) {
                    continue;
                }
                $seen[$key] = true;
                $isCc = $recipient->recipient_type === 'cc';
                $this->notifications->notify(
                    $user,
                    $isCc ? 'correspondence_cc' : 'correspondence_forwarded',
                    $isCc ? 'CC — correspondence shared for information' : 'Correspondence forwarded to you',
                    $isCc ? 'No action is required.' : 'Open the correspondence to review the full thread.',
                    null,
                    $mail,
                    "correspondence.forward.{$forward->id}.{$recipient->recipient_type}.{$user->id}",
                    'correspondence_updates',
                    $forward->correspondence->confidentiality !== 'normal',
                );
            }
        }
    }

    /** @param array<string, mixed> $recipient */
    private function alreadyActiveRecipient(Correspondence $correspondence, array $recipient): bool
    {
        return $correspondence->recipients()
            ->where('active', true)
            ->where(function ($existing) use ($recipient) {
                if (isset($recipient['user_id'])) {
                    $existing->where('user_id', $recipient['user_id']);
                } elseif (isset($recipient['organizational_unit_id'])) {
                    $existing->where('organizational_unit_id', $recipient['organizational_unit_id']);
                } elseif (isset($recipient['department_id'])) {
                    $existing->where('target_type', 'department')->where('department_id', $recipient['department_id']);
                }
            })
            ->exists();
    }
}
