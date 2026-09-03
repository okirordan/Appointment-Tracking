<?php

namespace App\Services;

use App\Enums\AssignmentLevel;
use App\Enums\Role;
use App\Models\OfficeScheduleItem;
use App\Models\SecretaryOfficeAttachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SecretaryOfficeScope
{
    public function __construct(
        private SecretaryAuthorityService $authority,
        private OrganizationalScopeService $organizations,
    ) {}

    /** @return Builder<Task> */
    public function tasks(User $secretary, ?SecretaryOfficeAttachment $attachment = null): Builder
    {
        if ($secretary->role !== Role::Secretary) {
            return Task::query()->whereRaw('1 = 0');
        }

        $entityTaskIds = $this->authority->departmentTasks($secretary)->select('tasks.id');
        $attachment ??= $this->authority->attachment($secretary);
        $isPermanentSecretaryOffice = $attachment?->supervisor?->role === Role::Ps
            && in_array($attachment?->organizationalUnit?->code, ['OPS'], true);

        return Task::query()->where(function (Builder $visible) use ($secretary, $entityTaskIds, $isPermanentSecretaryOffice) {
            $visible
                ->where('assigned_to_user_id', $secretary->id)
                ->orWhereIn('tasks.id', $entityTaskIds)
                ->orWhere('current_assignee_user_id', $secretary->id)
                ->orWhere('current_reviewer_user_id', $secretary->id)
                ->orWhereHas('participants', fn (Builder $participants) => $participants
                    ->where('user_id', $secretary->id)
                    ->where('active', true));

            if ($isPermanentSecretaryOffice) {
                // Compatibility for pre-entity PS assignments. New records
                // are stamped with owner_organizational_unit_id instead.
                $visible->orWhere('assignment_level', AssignmentLevel::Ps->value);
            }
        });
    }

    /** @return Builder<OfficeScheduleItem> */
    public function scheduleItems(User $secretary, ?SecretaryOfficeAttachment $attachment = null): Builder
    {
        $attachment ??= $this->authority->attachment($secretary);
        $organizationalUnitId = $this->organizations->primaryUnit($secretary)?->id;
        $departmentId = $organizationalUnitId === null
            ? $this->authority->supportedDepartmentId($secretary)
            : null;
        $supervisorId = $attachment?->supervisor_user_id;

        return OfficeScheduleItem::query()->where(function (Builder $scope) use ($departmentId, $organizationalUnitId, $supervisorId) {
            if ($organizationalUnitId !== null) {
                $scope->where('organizational_unit_id', $organizationalUnitId);

                return;
            }

            if ($departmentId !== null) {
                $scope->where('department_id', $departmentId);

                return;
            }

            if ($supervisorId !== null) {
                $scope->where('office_supervisor_user_id', $supervisorId);

                return;
            }

            $scope->whereRaw('1 = 0');
        });
    }

    /** @return array{secretary_office_attachment_id: ?int, department_id: ?int, organizational_unit_id: ?int, office_supervisor_user_id: ?int} */
    public function scheduleAttributes(User $secretary, ?SecretaryOfficeAttachment $attachment = null): array
    {
        $attachment ??= $this->authority->attachment($secretary);

        $organizationalUnitId = $this->organizations->primaryUnit($secretary)?->id;

        return [
            'secretary_office_attachment_id' => $attachment?->id,
            'department_id' => $organizationalUnitId === null ? $this->authority->supportedDepartmentId($secretary) : null,
            'organizational_unit_id' => $organizationalUnitId,
            'office_supervisor_user_id' => $attachment?->supervisor_user_id,
        ];
    }
}
