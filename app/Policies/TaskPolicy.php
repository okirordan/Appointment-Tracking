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
        return $user->can('assignments.create')
            || $this->secretaryAuthority->allows($user, 'assignments.create')
            || in_array($user->role, [Role::Sysadmin, Role::Ps, Role::Commissioner, Role::Clerk], true);
    }

    /**
     * PROG-001: only the assigned user updates progress; the sysadmin
     * retains an explicit audited override.
     */
    public function updateProgress(User $user, Task $task): bool
    {
        if ($task->workflow_status->isClosed()) {
            return false;
        }

        return in_array($user->id, [$task->assigned_to_user_id, $task->current_assignee_user_id], true)
            || $user->can('assignments.update') && $task->workflowSteps()->where('recipient_user_id', $user->id)->exists()
            || $user->role === Role::Sysadmin;
    }

    public function annotate(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function downloadEvidence(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->role === Role::Sysadmin;
    }

    public function delegate(User $user, Task $task): bool
    {
        return ! $task->workflow_status->isClosed()
            && ($user->can('assignments.delegate')
                || $this->secretaryAuthority->allows($user, 'assignments.delegate')
                || in_array($user->role, [Role::Sysadmin, Role::Ps, Role::Commissioner], true))
            && ($task->current_assignee_user_id === $user->id || $task->assigned_to_user_id === $user->id || $user->can('assignments.reassign') || $user->role === Role::Sysadmin);
    }

    public function submit(User $user, Task $task): bool
    {
        return ! $task->workflow_status->isClosed()
            && ($task->current_assignee_user_id === $user->id || $task->workflowSteps()->where('is_current', true)->where('recipient_user_id', $user->id)->exists());
    }

    public function review(User $user, Task $task): bool
    {
        return ! $task->workflow_status->isClosed()
            && ($task->current_reviewer_user_id === $user->id
                || ($user->can('assignments.review') || $this->secretaryAuthority->allows($user, 'assignments.review'))
                    && $task->workflowSteps()->where('sender_user_id', $user->id)->exists()
                || $user->role === Role::Sysadmin);
    }

    public function reassign(User $user, Task $task): bool
    {
        return ! $task->workflow_status->isClosed()
            && ($user->can('assignments.reassign')
                || $this->secretaryAuthority->allows($user, 'assignments.reassign')
                || in_array($user->role, [Role::Sysadmin, Role::Ps, Role::Commissioner], true));
    }
}
