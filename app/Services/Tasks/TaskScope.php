<?php

namespace App\Services\Tasks;

use App\Enums\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\DepartmentAccessService;
use App\Services\SecretaryOfficeScope;
use Illuminate\Database\Eloquent\Builder;

class TaskScope
{
    public function __construct(
        private SecretaryOfficeScope $secretaryOffices,
        private DepartmentAccessService $departments,
        private AssignmentTargetService $targets,
    ) {}

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
            $officeTaskIds = $this->secretaryOffices->tasks($user)->select('tasks.id');
            $officeIds = $this->targets->officeIdsFor($user);
            $departmentIds = $this->targets->departmentIdsFor($user);

            return $query->where(function (Builder $visible) use ($officeTaskIds, $officeIds, $departmentIds) {
                $visible->whereIn('tasks.id', $officeTaskIds);
                if ($officeIds !== []) {
                    $visible->orWhere(fn (Builder $office) => $office
                        ->where('assignment_target_type', 'office')
                        ->whereIn('assigned_to_organizational_unit_id', $officeIds));
                }
                if ($departmentIds !== []) {
                    $visible->orWhere(fn (Builder $department) => $department
                        ->where('assignment_target_type', 'department')
                        ->whereIn('assigned_to_department_id', $departmentIds));
                }
            });
        }

        $subordinateIds = $this->subordinateIds($user);
        $officeIds = $this->targets->officeIdsFor($user);
        $departmentIds = $this->targets->departmentIdsFor($user);

        return $query->where(function (Builder $visible) use ($user, $subordinateIds, $officeIds, $departmentIds) {
            $visible
                ->where('creator_user_id', $user->id)
                ->orWhere('owner_user_id', $user->id)
                ->orWhere('assigned_by_user_id', $user->id)
                ->orWhere('assigned_to_user_id', $user->id)
                ->orWhere('current_assignee_user_id', $user->id)
                ->orWhere('responsible_user_id', $user->id)
                ->orWhere('current_reviewer_user_id', $user->id)
                ->orWhere('final_approver_user_id', $user->id)
                ->orWhereHas('participants', fn (Builder $q) => $q
                    ->where('user_id', $user->id)
                    ->where('active', true));

            if ($officeIds !== []) {
                $visible->orWhere(fn (Builder $office) => $office
                    ->where('assignment_target_type', 'office')
                    ->whereIn('assigned_to_organizational_unit_id', $officeIds));
            }
            if ($departmentIds !== []) {
                $visible->orWhere(fn (Builder $department) => $department
                    ->where('assignment_target_type', 'department')
                    ->whereIn('assigned_to_department_id', $departmentIds));
            }

            if ($subordinateIds !== []) {
                $visible
                    ->orWhereIn('creator_user_id', $subordinateIds)
                    ->orWhereIn('assigned_by_user_id', $subordinateIds)
                    ->orWhereIn('assigned_to_user_id', $subordinateIds)
                    ->orWhereIn('current_assignee_user_id', $subordinateIds)
                    ->orWhereIn('responsible_user_id', $subordinateIds)
                    ->orWhereHas('workflowSteps', fn (Builder $step) => $step
                        ->where('is_current', true)
                        ->whereIn('recipient_user_id', $subordinateIds));
            }
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
        return $this->targets->eligibleUsers()->whereKeyNot($user->id);
    }

    /** @return list<int> */
    private function subordinateIds(User $user): array
    {
        $seen = [];
        $frontier = [$user->id];

        while ($frontier !== []) {
            $next = User::query()
                ->where('active', true)
                ->whereIn('supervisor_user_id', $frontier)
                ->whereNotIn('id', $seen)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $next = array_values(array_diff($next, $seen, [$user->id]));
            if ($next === []) {
                break;
            }

            $seen = array_values(array_unique([...$seen, ...$next]));
            $frontier = $next;
        }

        return $seen;
    }
}
