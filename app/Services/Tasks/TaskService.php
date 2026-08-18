<?php

namespace App\Services\Tasks;

use App\Enums\AssignmentLevel;
use App\Enums\CorrespondenceLifecycleStatus;
use App\Enums\CorrespondenceStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\AnnotationTitle;
use App\Models\AssignmentParticipant;
use App\Models\AssignmentWorkflowStep;
use App\Models\CorrespondenceUpdate;
use App\Models\EvidenceAttachment;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
        private AssignmentTargetService $targets,
    ) {}

    /**
     * Create a task with a transactionally generated unique reference
     * (TASK-CRT-004/005): PS-YYYY-NNN or <DEPTCODE>-YYYY-NNN.
     *
     * @param  array{title: string, description: ?string, assigned_to_user_id?: int, assigned_to_user_ids?: list<int>, priority: string, due_date: ?string, instructions: ?string, attachments?: list<UploadedFile>}  $data
     */
    public function create(User $creator, array $data): Task
    {
        return $this->createWithLink($creator, $data);
    }

    /**
     * Create a task and persist an optional source-system link inside the
     * same transaction. Notifications and audits run only after commit.
     */
    public function createWithLink(User $creator, array $data, ?callable $link = null): Task
    {
        $target = $this->targets->resolve($data);
        $assignees = $target['users'];

        $level = in_array($creator->role, [Role::Ps, Role::Clerk], true)
            ? AssignmentLevel::Ps
            : AssignmentLevel::Department;

        $primaryAssignee = $assignees->first();
        $departmentId = $target['department']?->id ?? $primaryAssignee?->department_id;
        $storedKeys = [];

        try {
            $task = DB::transaction(function () use (
                $creator,
                $assignees,
                $primaryAssignee,
                $data,
                $level,
                $departmentId,
                $target,
                $link,
                &$storedKeys,
            ) {
                $prefix = $level === AssignmentLevel::Ps
                    ? 'PS'
                    : ($target['department']?->code ?? $primaryAssignee?->department?->code ?? 'DEPT');

                $reference = $this->nextReference($prefix);
                $assigneeSnapshot = $target['label'];
                if ($target['type'] === 'multiple' && $assignees->count() > 1) {
                    $assigneeSnapshot = $primaryAssignee->full_name;
                    $assigneeSnapshot .= ' + '.($assignees->count() - 1).' other'.($assignees->count() === 2 ? '' : 's');
                }

                $task = Task::create([
                    'reference' => $reference,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'assignment_level' => $level->value,
                    'assignment_target_type' => $target['type'],
                    'assigned_by_user_id' => $creator->id,
                    'assigned_by_role_snapshot' => $creator->roleLabel(),
                    'assigned_by_department_id' => $creator->department_id,
                    'creator_user_id' => $creator->id,
                    'owner_user_id' => $creator->id,
                    'assigned_to_user_id' => in_array($target['type'], ['individual', 'multiple'], true) ? $primaryAssignee?->id : null,
                    'current_assignee_user_id' => $target['type'] === 'individual' ? $primaryAssignee?->id : null,
                    'responsible_user_id' => $target['type'] === 'individual' ? $primaryAssignee?->id : null,
                    'assigned_to_name_snapshot' => $assigneeSnapshot,
                    'department_id' => $departmentId,
                    'assigned_to_organizational_unit_id' => $target['office']?->id,
                    'assigned_to_department_id' => $target['type'] === 'department' ? $target['department']?->id : null,
                    'division_id' => $primaryAssignee?->division_id ?? $target['office']?->division_id,
                    'workstream_id' => $data['workstream_id'] ?? null,
                    'priority' => $data['priority'],
                    'due_date' => $data['due_date'] ?? null,
                    'original_due_date' => $data['due_date'] ?? null,
                    'workflow_status' => TaskStatus::Assigned->value,
                    'execution_status' => 'not_started',
                    'review_status' => 'not_submitted',
                    'approval_status' => 'pending',
                    'progress_percent' => 0,
                    'initial_instruction' => $data['instructions'] ?? null,
                ]);

                $history = $this->recordHistory(
                    $task,
                    $creator,
                    'Created',
                    TaskStatus::Assigned,
                    0,
                    'Assignment created and issued.',
                );

                if (filled($data['instructions'] ?? null)) {
                    $this->recordHistory($task, $creator, 'Annotated', null, null, trim((string) $data['instructions']));
                }

                AssignmentParticipant::firstOrCreate([
                    'task_id' => $task->id,
                    'user_id' => $creator->id,
                    'participant_type' => 'creator',
                ], [
                    'active' => true,
                    'assigned_at' => now(),
                    'added_by_user_id' => $creator->id,
                ]);
                AssignmentParticipant::firstOrCreate([
                    'task_id' => $task->id,
                    'user_id' => $creator->id,
                    'participant_type' => 'owner',
                ], [
                    'active' => true,
                    'assigned_at' => now(),
                    'added_by_user_id' => $creator->id,
                ]);

                foreach ($assignees as $index => $assignee) {
                    AssignmentWorkflowStep::create([
                        'task_id' => $task->id,
                        'sender_user_id' => $creator->id,
                        'recipient_user_id' => $assignee->id,
                        'position_id' => $assignee->currentPositionAssignment?->position_id,
                        'sequence' => $index + 1,
                        'status' => 'active',
                        'instructions' => $data['instructions'] ?? null,
                        'assigned_at' => now(),
                        'due_at' => $data['due_date'] ?? null,
                        'is_current' => true,
                        'is_direct' => true,
                    ]);
                    AssignmentParticipant::firstOrCreate([
                        'task_id' => $task->id,
                        'user_id' => $assignee->id,
                        'participant_type' => 'assignee',
                    ], [
                        'active' => true,
                        'assigned_at' => now(),
                        'added_by_user_id' => $creator->id,
                    ]);
                }

                foreach ($data['attachments'] ?? [] as $file) {
                    $storedKeys[] = $this->storeEvidence($task, $history, $creator, $file);
                }

                if ($link !== null) {
                    $link($task);
                }

                $this->audit->log('task', "Created task {$task->reference}", $creator, 'Task', $task->id, [
                    'title' => $task->title,
                    'assigned_to' => $assignees->pluck('full_name')->all(),
                    'assignment_target_type' => $target['type'],
                    'assignment_target' => $target['label'],
                    'assigned_by_role' => $task->assigned_by_role_snapshot,
                    'assigned_by_department_id' => $task->assigned_by_department_id,
                    'priority' => $task->priority->value,
                    'due_date' => $task->due_date?->toDateString(),
                    'supporting_attachments' => count($data['attachments'] ?? []),
                ]);

                return $task;
            });
        } catch (\Throwable $exception) {
            foreach ($storedKeys as $key) {
                Storage::disk('evidence')->delete($key);
            }
            throw $exception;
        }

        foreach ($assignees as $assignee) {
            $this->notifications->notify(
                $assignee,
                'new_assignment',
                "New assignment {$task->reference}: {$task->title}",
                $target['type'] === 'office' || $target['type'] === 'department'
                    ? "Assigned to {$target['label']} by {$creator->full_name}"
                    : "Assigned by {$creator->full_name}",
                $task,
                null,
                "assignment.created.{$task->id}.{$assignee->id}",
                $target['type'] !== 'individual' ? 'office_correspondence' : 'new_assignments',
            );
        }

        return $task;
    }

    /**
     * Progress update (PRD §12.10): mandatory note; completion requires
     * 100% progress and at least one evidence attachment; evidence upload
     * and status change are one transaction (PROG-004/005).
     *
     * @param  array{status: string, progress: int, note: string}  $data
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $links
     */
    public function updateProgress(User $user, Task $task, array $data, array $files = [], array $links = []): Task
    {
        $status = TaskStatus::from($data['status']);
        $progress = (int) $data['progress'];

        if ($status === TaskStatus::Completed) {
            if ($progress !== 100) {
                throw ValidationException::withMessages(['progress' => 'Completion requires 100% progress.']);
            }
            if ($files === [] && $links === [] && ! $task->evidence()->exists()) {
                throw ValidationException::withMessages(['evidence' => 'Completion requires at least one evidence attachment.']);
            }
        }

        $storedKeys = [];

        try {
            $task = DB::transaction(function () use ($user, $task, $status, $progress, $data, $files, $links, &$storedKeys) {
                $history = $this->recordHistory($task, $user,
                    $status === TaskStatus::Completed ? 'Completed' : 'Progress Updated',
                    $status, $progress, $data['note']);

                foreach ($files as $file) {
                    $storedKeys[] = $this->storeEvidence($task, $history, $user, $file);
                }

                foreach ($links as $link) {
                    $this->storeEvidenceLink($task, $history, $user, $link);
                }

                $task->update([
                    'workflow_status' => $status->value,
                    'progress_percent' => $progress,
                    'completed_at' => $status === TaskStatus::Completed ? now() : $task->completed_at,
                    'archived_at' => $status === TaskStatus::Archived ? now() : $task->archived_at,
                ]);

                return $task;
            });
        } catch (\Throwable $e) {
            // A failed transaction must never leave orphaned files behind.
            foreach ($storedKeys as $key) {
                Storage::disk('evidence')->delete($key);
            }
            throw $e;
        }

        $this->audit->log('task', "Progress update on {$task->reference}: {$status->label()} ({$progress}%)",
            $user, 'Task', $task->id, ['note' => $data['note'], 'files' => count($files), 'links' => count($links)]);

        if (! $status->isClosed()) {
            $this->syncCorrespondenceStatus(
                $task,
                $user,
                $status === TaskStatus::AwaitingReview
                    ? CorrespondenceLifecycleStatus::AwaitingResponse
                    : CorrespondenceLifecycleStatus::ActionRequired,
                "Assignment {$task->reference} changed to {$status->label()}.",
            );
        }

        if (in_array($status, [TaskStatus::Completed, TaskStatus::Archived], true)) {
            $mail = MailRecord::query()
                ->where('task_id', $task->id)
                ->orWhereHas('correspondence.recipients', fn ($recipient) => $recipient->where('task_id', $task->id))
                ->first();
            if ($mail !== null) {
                $mailStatus = $status === TaskStatus::Archived ? CorrespondenceStatus::Archived : CorrespondenceStatus::Completed;
                $mail->update([
                    'status' => $mailStatus,
                    'last_processed_by_user_id' => $user->id,
                    'archived_at' => $mailStatus === CorrespondenceStatus::Archived ? now() : $mail->archived_at,
                ]);
                if ($mail->correspondence !== null) {
                    $before = $mail->correspondence->current_status;
                    $hasOtherOpenAction = $mail->correspondence->recipients()
                        ->where('purpose', 'action_required')
                        ->where('active', true)
                        ->whereNotNull('task_id')
                        ->where('task_id', '!=', $task->id)
                        ->whereHas('task', fn ($linkedTask) => $linkedTask
                            ->whereNotIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value]))
                        ->exists();
                    $after = $hasOtherOpenAction
                        ? CorrespondenceLifecycleStatus::ActionRequired
                        : CorrespondenceLifecycleStatus::Closed;
                    $mail->correspondence->update([
                        'current_status' => $after,
                        'last_activity_at' => now(),
                        'closed_at' => $after === CorrespondenceLifecycleStatus::Closed ? now() : null,
                        'lock_version' => $mail->correspondence->lock_version + 1,
                    ]);
                    CorrespondenceUpdate::create([
                        'correspondence_id' => $mail->correspondence->id,
                        'task_id' => $task->id,
                        'type' => 'status_change',
                        'body' => "Linked assignment {$task->reference} was {$status->label()}.",
                        'status_from' => $before->value,
                        'status_to' => $after->value,
                        'performed_by_user_id' => $user->id,
                        'performed_by_name_snapshot' => $user->full_name,
                        'performed_by_title_snapshot' => $user->title,
                        'performed_by_role_snapshot' => $user->roleName(),
                        'created_at' => now(),
                    ]);
                }
                $this->audit->log(
                    'mail',
                    "Linked assignment {$task->reference} marked correspondence {$mail->register_number} {$mailStatus->label()}",
                    $user,
                    'MailRecord',
                    $mail->id,
                    ['task_id' => $task->id, 'status' => $mailStatus->value],
                );
            }
        }

        $assigner = $task->assignedBy;
        if ($assigner !== null && $assigner->id !== $user->id) {
            $message = match ($status) {
                TaskStatus::AwaitingReview => "{$user->full_name} submitted {$task->reference} for review",
                TaskStatus::Completed => "{$user->full_name} completed {$task->reference}",
                default => "{$user->full_name} updated {$task->reference} to {$status->label()} ({$progress}%)",
            };
            $type = $status === TaskStatus::AwaitingReview ? 'review' : 'progress';
            $this->notifications->notify($assigner, $type, $message, $data['note'], $task);
        }

        return $task->refresh();
    }

    /**
     * Annotations are immutable official records (CORR-003) appended to
     * task history; the assignee is notified (CORR-006).
     */
    /** @param array{text: string, origin_title_id?: int|null, recipient_title_id?: int|null} $data */
    public function annotate(User $user, Task $task, array $data): void
    {
        $titles = AnnotationTitle::query()
            ->whereKey(array_filter([$data['origin_title_id'] ?? null, $data['recipient_title_id'] ?? null]))
            ->get()
            ->keyBy('id');
        $origin = $titles->get($data['origin_title_id'] ?? 0);
        $recipientTitle = $titles->get($data['recipient_title_id'] ?? 0);
        $history = $this->recordHistory($task, $user, 'Annotated', null, null, $data['text'], [
            'annotation_origin_title_id' => $origin?->id,
            'annotation_recipient_title_id' => $recipientTitle?->id,
            'annotation_origin_snapshot' => $origin === null ? null : "{$origin->shorthand} — {$origin->full_title}",
            'annotation_recipient_snapshot' => $recipientTitle === null ? null : "{$recipientTitle->shorthand} — {$recipientTitle->full_title}",
        ]);

        $this->audit->log('task', "Annotation added to {$task->reference}", $user, 'Task', $task->id, [
            'annotation_history_id' => $history->id,
            'origin_title' => $history->annotation_origin_snapshot,
            'recipient_title' => $history->annotation_recipient_snapshot,
        ]);

        // Include legacy single-assignee tasks that predate workflow steps,
        // then merge current workflow and dynamic group recipients.
        $recipients = collect([
            $task->assignedTo,
            $task->currentAssignee,
            $task->responsibleOfficer,
        ])->filter()->concat($task->workflowSteps()
            ->where('is_current', true)
            ->with('recipient')
            ->get()
            ->pluck('recipient')
            ->filter());
        if ($task->assignment_target_type === 'office' && $task->assigned_to_organizational_unit_id !== null) {
            $recipients = $recipients->concat($this->targets->officeMembers($task->assigned_to_organizational_unit_id));
        } elseif ($task->assignment_target_type === 'department' && $task->assigned_to_department_id !== null) {
            $recipients = $recipients->concat($this->targets->departmentMembers($task->assigned_to_department_id));
        }

        foreach ($recipients->unique('id') as $recipient) {
            if ($recipient->id === $user->id) {
                continue;
            }
            $this->notifications->notify(
                $recipient,
                'annotation',
                "New instruction added to {$task->reference}",
                $data['text'],
                $task,
                null,
                "assignment.annotation.{$history->id}.{$recipient->id}",
                'annotation_updates',
                $task->mailRecord?->confidentiality !== null && $task->mailRecord->confidentiality !== 'normal',
            );
        }
    }

    /** @param array<string, mixed> $extra */
    private function recordHistory(Task $task, User $user, string $actionType, ?TaskStatus $status, ?int $progress, ?string $note, array $extra = []): TaskHistory
    {
        return TaskHistory::create([
            'task_id' => $task->id,
            'action_type' => $actionType,
            'note' => $note,
            'status' => $status?->value,
            'progress_percent' => $progress,
            'performed_by_user_id' => $user->id,
            'performed_by_name_snapshot' => $user->full_name,
            'performed_by_title_snapshot' => $user->title,
            'performed_by_role' => $user->role->value,
            'created_at' => now(),
            ...$extra,
        ]);
    }

    /** @return string the storage key written, so callers can clean up on rollback */
    private function storeEvidence(Task $task, TaskHistory $history, User $user, UploadedFile $file): string
    {
        $key = $file->store((string) $task->id, ['disk' => 'evidence']);

        if ($key === false) {
            throw ValidationException::withMessages(['evidence' => "Upload failed for {$file->getClientOriginalName()}."]);
        }

        EvidenceAttachment::create([
            'task_id' => $task->id,
            'history_id' => $history->id,
            'source_type' => 'file',
            'original_filename' => $file->getClientOriginalName(),
            'storage_key' => $key,
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by_user_id' => $user->id,
            'uploaded_at' => now(),
        ]);

        return $key;
    }

    private function storeEvidenceLink(Task $task, TaskHistory $history, User $user, string $link): void
    {
        $url = trim($link);
        $host = parse_url($url, PHP_URL_HOST);

        EvidenceAttachment::create([
            'task_id' => $task->id,
            'history_id' => $history->id,
            'source_type' => 'link',
            'original_filename' => $host === null ? 'Evidence link' : 'Link to '.$host,
            'storage_key' => '',
            'external_url' => $url,
            'mime_type' => 'text/uri-list',
            'size_bytes' => 0,
            'checksum' => hash('sha256', $url),
            'uploaded_by_user_id' => $user->id,
            'uploaded_at' => now(),
        ]);
    }

    /**
     * Locks the tasks table rows for the prefix so concurrent creates
     * cannot mint the same reference (TASK-CRT-005).
     */
    private function nextReference(string $prefix): string
    {
        $year = now()->year;
        $stem = "{$prefix}-{$year}-";

        $last = Task::withTrashed()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last === null ? 1 : ((int) substr($last, strlen($stem))) + 1;

        return $stem.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function syncCorrespondenceStatus(
        Task $task,
        User $actor,
        CorrespondenceLifecycleStatus $status,
        string $body,
    ): void {
        $mail = MailRecord::query()
            ->where('task_id', $task->id)
            ->orWhereHas('correspondence.recipients', fn ($recipient) => $recipient->where('task_id', $task->id))
            ->first();
        $correspondence = $mail?->correspondence;
        if ($correspondence === null || $correspondence->current_status === $status) {
            return;
        }

        $before = $correspondence->current_status;
        $correspondence->update([
            'current_status' => $status,
            'last_activity_at' => now(),
            'closed_at' => null,
            'lock_version' => $correspondence->lock_version + 1,
        ]);
        CorrespondenceUpdate::create([
            'correspondence_id' => $correspondence->id,
            'task_id' => $task->id,
            'type' => 'status_change',
            'body' => $body,
            'status_from' => $before->value,
            'status_to' => $status->value,
            'performed_by_user_id' => $actor->id,
            'performed_by_name_snapshot' => $actor->full_name,
            'performed_by_title_snapshot' => $actor->title,
            'performed_by_role_snapshot' => $actor->roleName(),
            'created_at' => now(),
        ]);
    }
}
