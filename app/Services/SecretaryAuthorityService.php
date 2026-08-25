<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SecretaryAuthorityService
{
    private const DEPARTMENT_SECRETARY_PERMISSIONS = [
        'assignments.create',
        'assignments.update',
        'assignments.delegate',
        'mail.manage',
        'mail.assign',
        'mail.view.sensitive',
    ];

    /** @return array<string, string> */
    public function availablePermissions(): array
    {
        return [
            'assignments.create' => 'Prepare and issue assignments',
            'assignments.update' => 'Add departmental progress updates',
            'assignments.delegate' => 'Delegate an active assignment',
            'assignments.direct' => 'Use direct assignment routing',
            'assignments.review' => 'Review submissions',
            'assignments.return' => 'Return work for correction',
            'assignments.approve' => 'Approve submissions',
            'assignments.reject' => 'Reject submissions',
            'assignments.reassign' => 'Reassign an active workflow step',
            'mail.manage' => 'Capture and update correspondence',
            'mail.assign' => 'Convert incoming mail to an assignment',
            'mail.view.sensitive' => 'View confidential and restricted correspondence',
            'reports.export' => 'Export office reports',
        ];
    }

    public function attachment(User $user): ?SecretaryOfficeAttachment
    {
        $attachment = $user->currentSecretaryAttachment()
            ->with(['supervisor', 'organizationalUnit'])
            ->first();

        return $attachment instanceof SecretaryOfficeAttachment ? $attachment : null;
    }

    public function allows(User $user, string $permission): bool
    {
        $attachment = $this->attachment($user);
        if ($attachment === null) {
            return $user->role === Role::Secretary
                && $user->department_id !== null
                && in_array($permission, self::DEPARTMENT_SECRETARY_PERMISSIONS, true);
        }

        if ($attachment->supervisor->role === Role::Ps
            && in_array($permission, ['mail.manage', 'mail.assign', 'mail.view.sensitive'], true)) {
            return true;
        }

        $departmentId = $attachment->organizationalUnit?->department_id
            ?? $attachment->supervisor?->department_id
            ?? $user->department_id;
        if ($user->role === Role::Secretary
            && $departmentId !== null
            && in_array($permission, self::DEPARTMENT_SECRETARY_PERMISSIONS, true)) {
            return true;
        }

        if (! $attachment->delegated_actions_permitted) {
            return false;
        }

        return in_array($permission, $attachment->delegated_permissions ?? [], true);
    }

    /**
     * The department whose day-to-day assignment register this secretary
     * supports. PS-office secretaries intentionally return null here: their
     * authority continues to come from explicit office attachment rules.
     */
    public function supportedDepartmentId(User $user): ?int
    {
        if ($user->role !== Role::Secretary) {
            return null;
        }

        $attachment = $this->attachment($user);
        $departmentId = $attachment?->organizationalUnit?->department_id
            ?? $attachment?->supervisor?->department_id
            ?? $user->department_id;

        return $departmentId === null ? null : (int) $departmentId;
    }

    /**
     * Every assignment that belongs to, is currently held in, or has passed
     * through the supported department. Keeping historical recipients in the
     * scope preserves the full audit trail after a later reassignment.
     *
     * @return Builder<Task>
     */
    public function departmentTasks(User $secretary): Builder
    {
        $departmentId = $this->supportedDepartmentId($secretary);
        if ($departmentId === null) {
            return Task::query()->whereRaw('1 = 0');
        }

        return Task::query()->where(function (Builder $visible) use ($departmentId) {
            $visible
                ->where('department_id', $departmentId)
                ->orWhere('assigned_to_department_id', $departmentId)
                ->orWhereHas('assignedTo', fn (Builder $user) => $user->where('department_id', $departmentId))
                ->orWhereHas('currentAssignee', fn (Builder $user) => $user->where('department_id', $departmentId))
                ->orWhereHas('responsibleOfficer', fn (Builder $user) => $user->where('department_id', $departmentId))
                ->orWhereHas('workflowSteps.recipient', fn (Builder $user) => $user->where('department_id', $departmentId));
        });
    }

    public function supportsTask(User $secretary, Task $task): bool
    {
        return $this->departmentTasks($secretary)->whereKey($task->id)->exists();
    }

    /** @return Builder<User> */
    public function departmentOfficers(User $secretary): Builder
    {
        $departmentId = $this->supportedDepartmentId($secretary);
        if ($departmentId === null) {
            return User::query()->whereRaw('1 = 0');
        }

        return User::query()
            ->whereIn('role', [Role::Commissioner->value, Role::Officer->value])
            ->where(function (Builder $members) use ($departmentId) {
                $members->where('department_id', $departmentId)
                    ->orWhereHas(
                        'currentPositionAssignment.position.organizationalUnit',
                        fn (Builder $unit) => $unit->where('department_id', $departmentId),
                    );
            });
    }

    public function canAssignDepartmentOfficer(User $secretary, User $recipient): bool
    {
        return $this->departmentOfficers($secretary)->whereKey($recipient->id)->exists();
    }
}
