<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\SecretaryAuthorityService;
use App\Services\Tasks\TaskScope;

class TaskPolicy
{
    public function __construct(private TaskScope $scope, private SecretaryAuthorityService $secretaryAuthority) {}

    public function view(User $user, Task $task): bool
    {
        return $this->scope->allows($user, $task);
    }

    public function create(User $user): bool
    {
        if ($user->role === Role::Sysadmin) {
            return false;
        }

        return $user->can('assignments.create')
            || $this->secretaryAuthority->allows($user, 'assignments.create')
            || in_array($user->role, [Role::Ps, Role::Commissioner, Role::Clerk], true);
    }

    /**
     * Progress is updated by the current holder or by the Department
     * Secretary acting within the supported department. The service records
     * both the actual actor and the officer represented by that update.
     */
    public function updateProgress(User $user, Task $task): bool
    {
        if ($task->workflow_status->isClosed()) {
            return false;
        }

        return $this->secretaryAuthority->supportsTask($user, $task)
            || in_array($user->id, [$task->assigned_to_user_id, $task->current_assignee_user_id], true)
            || $user->can('assignments.update')
                && $task->workflowSteps()
                    ->where('recipient_user_id', $user->id)
                    ->where('is_current', true)
                    ->exists();
    }

    public function annotate(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function downloadEvidence(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function delegate(User $user, Task $task): bool
    {
        if ($user->role === Role::Sysadmin) {
            return ! $task->workflow_status->isClosed()
                && $this->view($user, $task)
                && in_array($user->id, [$task->assigned_to_user_id, $task->current_assignee_user_id], true);
        }

        $departmentSecretarySupport = $this->secretaryAuthority->supportsTask($user, $task);

        return ! $task->workflow_status->isClosed()
            && $this->view($user, $task)
            && ($user->can('assignments.delegate')
                || $this->secretaryAuthority->allows($user, 'assignments.delegate')
                || $departmentSecretarySupport
                || in_array($user->role, [Role::Ps, Role::Commissioner], true))
            && ($task->current_assignee_user_id === $user->id
                || $task->assigned_to_user_id === $user->id
                || $user->can('assignments.reassign')
                || $departmentSecretarySupport);
    }

    public function submit(User $user, Task $task): bool
    {
        return ! $task->workflow_status->isClosed()
            && ($task->current_assignee_user_id === $user->id || $task->workflowSteps()->where('is_current', true)->where('recipient_user_id', $user->id)->exists());
    }

    public function review(User $user, Task $task): bool
    {
        if ($user->role === Role::Sysadmin) {
            return ! $task->workflow_status->isClosed()
                && $task->current_reviewer_user_id === $user->id;
        }

        return ! $task->workflow_status->isClosed()
            && ($task->current_reviewer_user_id === $user->id
                || ($user->can('assignments.review') || $this->secretaryAuthority->allows($user, 'assignments.review'))
                    && $task->workflowSteps()->where('sender_user_id', $user->id)->exists());
    }

    public function reassign(User $user, Task $task): bool
    {
        if ($user->role === Role::Sysadmin) {
            return false;
        }

        return ! $task->workflow_status->isClosed()
            && $this->view($user, $task)
            && ($user->can('assignments.reassign')
                || $this->secretaryAuthority->allows($user, 'assignments.reassign')
                || in_array($user->role, [Role::Ps, Role::Commissioner], true));
    }

    public function unassign(User $user, Task $task): bool
    {
        if ($task->workflow_status->isClosed()) {
            return false;
        }

        $hasCurrentAssignee = $task->current_assignee_user_id !== null
            || $task->workflowSteps()->where('is_current', true)->whereNotNull('recipient_user_id')->exists();
        if (! $hasCurrentAssignee) {
            return false;
        }

        if ($user->role === Role::Sysadmin) {
            return in_array($user->id, [$task->assigned_by_user_id, $task->creator_user_id, $task->owner_user_id], true)
                || $task->workflowSteps()->where('is_current', true)->where('sender_user_id', $user->id)->exists();
        }

        if (in_array($user->id, [$task->assigned_by_user_id, $task->creator_user_id, $task->owner_user_id], true)
            || $task->workflowSteps()->where('is_current', true)->where('sender_user_id', $user->id)->exists()) {
            return true;
        }

        if (! $this->scope->allows($user, $task)
            || (! $user->can('assignments.reassign')
                && ! $this->secretaryAuthority->allows($user, 'assignments.reassign')
                && ! in_array($user->role, [Role::Ps, Role::Commissioner], true))) {
            return false;
        }

        $actorLevel = $user->permissionRole()?->hierarchy_level;
        $assigneeLevels = $task->workflowSteps()
            ->where('is_current', true)
            ->with('recipient.roles')
            ->get()
            ->map(fn ($step) => $step->recipient?->permissionRole()?->hierarchy_level)
            ->filter();

        return $actorLevel !== null
            && $assigneeLevels->isNotEmpty()
            && $assigneeLevels->every(fn (int $level) => $actorLevel < $level);
    }
}
