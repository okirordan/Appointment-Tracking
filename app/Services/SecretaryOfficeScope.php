<?php

namespace App\Services;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Models\MailRecord;
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
        if ($attachment === null) {
            if ($secretary->role !== Role::Secretary || $secretary->department_id === null) {
                return Task::query()->whereRaw('1 = 0');
            }

            return Task::query()->where(function (Builder $visible) use ($secretary) {
                $visible->where('department_id', $secretary->department_id)
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

        return Task::query()->where(function (Builder $visible) use ($secretary, $supervisor, $unit) {
            $visible
                ->where('assigned_to_user_id', $secretary->id)
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

    /** @param Builder<MailRecord> $query
     * @return Builder<MailRecord> */
    public function applyMail(Builder $query, User $secretary, ?SecretaryOfficeAttachment $attachment = null): Builder
    {
        $attachment ??= $this->authority->attachment($secretary);
        if ($attachment === null) {
            if ($secretary->role !== Role::Secretary || $secretary->department_id === null) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function (Builder $visible) use ($secretary) {
                $visible->where('captured_by_user_id', $secretary->id)
                    ->orWhereHas(
                        'organizationalUnit',
                        fn (Builder $unit) => $unit->where('department_id', $secretary->department_id),
                    )
                    ->orWhereHas(
                        'task',
                        fn (Builder $task) => $task->where('department_id', $secretary->department_id),
                    );
            });
        }

        $supervisor = $attachment->supervisor;
        $unit = $attachment->organizationalUnit;
        $taskIds = $this->tasks($secretary, $attachment)->select('tasks.id');
        $names = array_values(array_filter(array_unique([
            $supervisor->full_name,
            $supervisor->title,
            $unit?->name,
        ])));

        $query->where(function (Builder $visible) use ($taskIds, $names, $supervisor, $unit) {
            $visible->where('office_supervisor_user_id', $supervisor->id)
                ->orWhereIn('task_id', $taskIds);
            if ($unit !== null) {
                $visible->orWhere('organizational_unit_id', $unit->id);
            }
            foreach ($names as $name) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $name).'%';
                $visible->orWhere('recipient_name', 'like', $like)
                    ->orWhere('sender_name', 'like', $like)
                    ->orWhere('correspondence_reference', 'like', $like);
            }
            if ($supervisor->role === Role::Ps) {
                $visible->orWhereIn('recipient_name', ['PS', 'P/S', 'Permanent Secretary', 'Office of the Permanent Secretary'])
                    ->orWhereIn('sender_name', ['PS', 'P/S', 'Permanent Secretary', 'Office of the Permanent Secretary']);
            }
        });

        if (! $this->authority->allows($secretary, 'mail.view.sensitive')) {
            $query->where('confidentiality', 'normal');
        }

        return $query;
    }

    public function allowsMail(User $secretary, MailRecord $mail): bool
    {
        return $this->applyMail(MailRecord::query()->whereKey($mail->id), $secretary)->exists();
    }
}
