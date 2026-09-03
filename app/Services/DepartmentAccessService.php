<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Department;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use App\Models\UserPosition;
use Illuminate\Database\Eloquent\Builder;

class DepartmentAccessService
{
    /**
     * Commissioners and configurable non-registry supervisor roles must use
     * department ownership instead of receiving organisation-wide registry
     * access merely because they hold a mail permission.
     */
    public function scopesMail(User $user): bool
    {
        if ($user->role === Role::Commissioner) {
            return true;
        }

        if (in_array($user->role, [Role::Sysadmin, Role::Ps, Role::Clerk, Role::Secretary], true)) {
            return false;
        }

        return $user->can('mail.view') || $user->can('mail.manage') || $user->can('mail.assign');
    }

    /**
     * Resolve the departments the user serves right now. Effective-dated
     * appointments are authoritative; the user profile is only a legacy
     * fallback for accounts that have never received a dated appointment.
     *
     * @return list<int>
     */
    public function currentDepartmentIds(User $user): array
    {
        $user->loadMissing('organizationalUnit');
        if ($user->organizational_unit_id !== null) {
            return $user->organizationalUnit?->department_id === null
                ? []
                : [(int) $user->organizationalUnit->department_id];
        }

        $positionIds = UserPosition::query()
            ->where('user_id', $user->id)
            ->where('user_positions.active', true)
            ->where(fn (Builder $period) => $period
                ->whereNull('user_positions.starts_at')
                ->orWhere('user_positions.starts_at', '<=', now()))
            ->where(fn (Builder $period) => $period
                ->whereNull('user_positions.ends_at')
                ->orWhere('user_positions.ends_at', '>=', now()))
            ->join('positions', 'positions.id', '=', 'user_positions.position_id')
            ->join('organizational_units', 'organizational_units.id', '=', 'positions.organizational_unit_id')
            ->whereNotNull('organizational_units.department_id')
            ->pluck('organizational_units.department_id');

        $secretaryIds = SecretaryOfficeAttachment::query()
            ->where('secretary_office_attachments.secretary_user_id', $user->id)
            ->where('secretary_office_attachments.active', true)
            ->where('secretary_office_attachments.starts_at', '<=', now())
            ->where(fn (Builder $period) => $period
                ->whereNull('secretary_office_attachments.ends_at')
                ->orWhere('secretary_office_attachments.ends_at', '>=', now()))
            ->leftJoin('organizational_units', 'organizational_units.id', '=', 'secretary_office_attachments.organizational_unit_id')
            ->leftJoin('users as supervisors', 'supervisors.id', '=', 'secretary_office_attachments.supervisor_user_id')
            ->get([
                'organizational_units.department_id as unit_department_id',
                'supervisors.department_id as supervisor_department_id',
            ])
            ->map(fn ($assignment) => $assignment->unit_department_id ?? $assignment->supervisor_department_id);

        $current = $positionIds
            ->merge($secretaryIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($current->isNotEmpty()) {
            return $current->all();
        }

        $hasEffectiveDatedAssignment = UserPosition::query()
            ->where('user_id', $user->id)
            ->exists()
            || SecretaryOfficeAttachment::query()
                ->where('secretary_office_attachments.secretary_user_id', $user->id)
                ->exists();

        if ($hasEffectiveDatedAssignment) {
            return [];
        }

        $legacy = Department::query()
            ->where('head_user_id', $user->id)
            ->pluck('id')
            ->when($user->department_id !== null, fn ($ids) => $ids->push($user->department_id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $legacy->all();
    }
}
