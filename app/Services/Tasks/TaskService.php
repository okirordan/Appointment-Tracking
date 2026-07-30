<?php

namespace App\Services\Tasks;

use App\Enums\AssignmentLevel;
use App\Enums\CorrespondenceStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\AssignmentParticipant;
use App\Models\AssignmentWorkflowStep;
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
        $assigneeIds = collect($data['assigned_to_user_ids'] ?? [])
            ->when(
                isset($data['assigned_to_user_id']),
                fn ($ids) => $ids->push((int) $data['assigned_to_user_id']),
            )
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $found = User::with('department')
            ->whereKey($assigneeIds)
            ->get()
            ->keyBy('id');
        $assignees = $assigneeIds
            ->map(fn (int $id) => $found->get($id))
            ->filter()
            ->values();

        if ($assignees->count() !== $assigneeIds->count() || $assignees->isEmpty()) {
            throw ValidationException::withMessages(['assigned_to_user_ids' => 'One or more selected assignees are unavailable.']);
        }
        foreach ($assignees as $assignee) {
            if (! $assignee->active || $assignee->locked || $assignee->trashed() || ! $assignee->isRoleActive()) {
                throw ValidationException::withMessages(['assigned_to_user_ids' => "{$assignee->full_name} is not available."]);
            }
        }

        $level = in_array($creator->role, [Role::Ps, Role::Clerk], true)
            ? AssignmentLevel::Ps
            : AssignmentLevel::Department;

        $primaryAssignee = $assignees->first();
        $departmentId = $primaryAssignee->department_id;
        $storedKeys = [];

        try {
            $task = DB::transaction(function () use (
                $creator,
                $assignees,
                $primaryAssignee,
                $data,
                $level,
                $departmentId,
                $link,
                &$storedKeys,
            ) {
                $prefix = $level === AssignmentLevel::Ps
                    ? 'PS'
                    : ($primaryAssignee->department?->code ?? 'DEPT');

                $reference = $this->nextReference($prefix);
                $assigneeSnapshot = $primaryAssignee->full_name;
                if ($assignees->count() > 1) {
                    $assigneeSnapshot .= ' + '.($assignees->count() - 1).' other'.($assignees->count() === 2 ? '' : 's');
                }

                $task = Task::create([
                    'reference' => $reference,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'assignment_level' => $level->value,
                    'assigned_by_user_id' => $creator->id,
                    'assigned_by_role_snapshot' => $creator->roleLabel(),
                    'assigned_by_department_id' => $creator->department_id,
                    'creator_user_id' => $creator->id,
                    'owner_user_id' => $creator->id,
                    'assigned_to_user_id' => $primaryAssignee->id,
                    'current_assignee_user_id' => $primaryAssignee->id,
                    'responsible_user_id' => $primaryAssignee->id,
                    'assigned_to_name_snapshot' => $assigneeSnapshot,
                    'department_id' => $departmentId,
                    'division_id' => $primaryAssignee->division_id,
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
                    ($data['instructions'] ?? null) ?: 'Task created and issued.',
                );

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

                return $task;
            });
        } catch (\Throwable $exception) {
            foreach ($storedKeys as $key) {
                Storage::disk('evidence')->delete($key);
            }
            throw $exception;
        }

        $this->audit->log('task', "Created task {$task->reference}", $creator, 'Task', $task->id, [
            'title' => $task->title,
            'assigned_to' => $assignees->pluck('full_name')->all(),
            'assigned_by_role' => $task->assigned_by_role_snapshot,
            'assigned_by_department_id' => $task->assigned_by_department_id,
            'priority' => $task->priority->value,
            'due_date' => $task->due_date?->toDateString(),
            'supporting_attachments' => count($data['attachments'] ?? []),
        ]);

        foreach ($assignees as $assignee) {
            $this->notifications->notify(
                $assignee,
                'task',
                "New assignment {$task->reference}: {$task->title}",
                "Assigned by {$creator->full_name}",
                $task,
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

        if (in_array($status, [TaskStatus::Completed, TaskStatus::Archived], true)) {
            $mail = MailRecord::where('task_id', $task->id)->first();
            if ($mail !== null) {
                $mailStatus = $status === TaskStatus::Archived ? CorrespondenceStatus::Archived : CorrespondenceStatus::Completed;
                $mail->update([
                    'status' => $mailStatus,
                    'last_processed_by_user_id' => $user->id,
                    'archived_at' => $mailStatus === CorrespondenceStatus::Archived ? now() : $mail->archived_at,
                ]);
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
    public function annotate(User $user, Task $task, string $text): void
    {
        $this->recordHistory($task, $user, 'Annotated', null, null, $text);

        $this->audit->log('task', "Annotation added to {$task->reference}", $user, 'Task', $task->id);

        $assignee = $task->assignedTo;
        if ($assignee !== null && $assignee->id !== $user->id) {
            $this->notifications->notify($assignee, 'annotation',
                "New instruction added to {$task->reference}", $text, $task);
        }
    }

    private function recordHistory(Task $task, User $user, string $actionType, ?TaskStatus $status, ?int $progress, ?string $note): TaskHistory
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
}
