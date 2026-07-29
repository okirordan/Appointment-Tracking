<?php

namespace App\Services\Tasks;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\SecretaryOfficeScope;
use Illuminate\Database\Eloquent\Builder;

class TaskScope
{
    public function __construct(private SecretaryOfficeScope $secretaryOffices) {}

    /**
     * The tasks a user is allowed to see (PRD §10). This is the single
     * scoping rule reused by lists, dashboards, search, and reports —
     * never trust client-supplied scope.
     *
     * @return Builder<Task>
     */
    public function query(User $user): Builder
    {
        $query = Task::query();

        if ($user->can('assignments.view.all')) {
            return $query;
        }

        if (in_array($user->role, [Role::Sysadmin, Role::Ps], true)) {
            return $query;
        }

        if ($user->role === Role::Secretary && $user->currentSecretaryAttachment()->exists()) {
            return $this->secretaryOffices->tasks($user);
        }

        $appointment = $user->currentPositionAssignment()->with('position')->first();
        if ($user->can('assignments.view.scope') && $appointment?->position !== null) {
            $unitId = $appointment->position->organizational_unit_id;

            return $query->where(function (Builder $scoped) use ($user, $unitId) {
                $scoped->whereIn('id', function ($subquery) use ($user) {
                    $subquery->select('task_id')->from('assignment_participants')->where('user_id', $user->id);
                })->orWhereIn('current_assignee_user_id', $user->subordinates()->select('id'))
                    ->orWhere('current_reviewer_user_id', $user->id)
                    ->when($unitId !== null, fn (Builder $q) => $q->orWhereHas('currentAssignee.currentPositionAssignment.position', fn (Builder $position) => $position->where('organizational_unit_id', $unitId)));
            });
        }

        return $query->where(function (Builder $visible) use ($user) {
            match ($user->role) {
                Role::Clerk => $visible->where(function (Builder $legacy) use ($user) {
                    $legacy->where('assigned_to_user_id', $user->id);
                }),
                Role::Commissioner, Role::Secretary => $visible->where(function (Builder $legacy) use ($user) {
                    $legacy->where(function (Builder $dept) use ($user) {
                        $dept->where('assignment_level', AssignmentLevel::Department->value)
                            ->where('department_id', $user->department_id);
                    })->orWhere('assigned_to_user_id', $user->id);
                }),
                Role::Officer => $visible->where('assigned_to_user_id', $user->id),
                default => $visible->whereRaw('1 = 0'),
            };

            $visible->orWhere('current_reviewer_user_id', $user->id)
                ->orWhereHas('participants', fn (Builder $q) => $q->where('user_id', $user->id));
        });
    }

    public function allows(User $user, Task $task): bool
    {
        return $this->query($user)->whereKey($task->id)->exists();
    }

    /**
     * Users the given user may assign tasks to (PRD §12.8 assignee rules).
     *
     * @return Builder<User>
     */
    public function assignableUsers(User $user): Builder
    {
        return User::query()
            ->where('active', true)
            ->where('locked', false)
            ->whereKeyNot($user->id)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('is_active', true));
    }
}
