<?php

namespace App\Services;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SecretaryOfficeScope
{
    public function __construct(private SecretaryAuthorityService $authority) {}

    /** @return Builder<Task> */
    public function tasks(User $secretary, ?SecretaryOfficeAttachment $attachment = null): Builder
    {
        $attachment ??= $this->authority->attachment($secretary);
        $departmentTaskIds = $this->authority->departmentTasks($secretary)->select('tasks.id');
        if ($attachment === null) {
            if ($secretary->role !== Role::Secretary || $secretary->department_id === null) {
                return Task::query()->whereRaw('1 = 0');
            }

            return Task::query()->where(function (Builder $visible) use ($secretary, $departmentTaskIds) {
                $visible->where('department_id', $secretary->department_id)
                    ->orWhereIn('tasks.id', $departmentTaskIds)
                    ->orWhere('assigned_to_user_id', $secretary->id)
                    ->orWhere('current_assignee_user_id', $secretary->id)
                    ->orWhere('current_reviewer_user_id', $secretary->id)
                    ->orWhereHas('participants', fn (Builder $participants) => $participants
                        ->where('user_id', $secretary->id)
                        ->where('active', true));
            });
        }

        $supervisor = $attachment->supervisor;
        $unit = $attachment->organizationalUnit;

        return Task::query()->where(function (Builder $visible) use ($secretary, $supervisor, $unit, $departmentTaskIds) {
            $visible
                ->where('assigned_to_user_id', $secretary->id)
                ->orWhereIn('tasks.id', $departmentTaskIds)
                ->orWhere('current_assignee_user_id', $secretary->id)
                ->orWhere('current_reviewer_user_id', $secretary->id)
                ->orWhereHas('participants', fn (Builder $participants) => $participants
                    ->where('user_id', $secretary->id)
                    ->where('active', true))
                ->orWhere(function (Builder $office) use ($supervisor) {
                    $office->where('assigned_by_user_id', $supervisor->id)
                        ->orWhere('creator_user_id', $supervisor->id)
                        ->orWhere('owner_user_id', $supervisor->id)
                        ->orWhere('assigned_to_user_id', $supervisor->id)
                        ->orWhere('current_assignee_user_id', $supervisor->id)
                        ->orWhere('current_reviewer_user_id', $supervisor->id)
                        ->orWhere('final_approver_user_id', $supervisor->id);
                });

            if ($supervisor->role === Role::Ps) {
                $visible->orWhere('assignment_level', AssignmentLevel::Ps->value);
            } elseif ($unit?->division_id !== null) {
                $visible->orWhere('division_id', $unit->division_id);
            } elseif ($unit?->department_id !== null) {
                $visible->orWhere('department_id', $unit->department_id);
            } elseif ($supervisor->department_id !== null) {
                $visible->orWhere('department_id', $supervisor->department_id);
            }
        });
    }
}
