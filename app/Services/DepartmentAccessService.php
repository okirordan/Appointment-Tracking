<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Department;
use App\Models\MailRecord;
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
            ->where('secretary_user_id', $user->id)
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
                ->where('secretary_user_id', $user->id)
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

    /**
     * Apply department ownership to a correspondence query.
     *
     * Records that belong to the Permanent Secretary's Office are never
     * department-owned — even when a stray department stamp exists — and only
     * become visible once they are formally forwarded or copied to the
     * department, one of its offices, or the viewer (CORR-ACCESS).
     *
     * @param  Builder<MailRecord>  $query
     * @return Builder<MailRecord>
     */
    public function applyMail(Builder $query, User $user): Builder
    {
        $departmentIds = $this->currentDepartmentIds($user);

        if ($departmentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $visible) use ($departmentIds, $user) {
            $visible->where(function (Builder $owned) use ($departmentIds) {
                $owned->where(function (Builder $scope) use ($departmentIds) {
                    $scope->whereIn('department_id', $departmentIds)
                        ->orWhere(function (Builder $legacy) use ($departmentIds) {
                            $legacy->whereNull('department_id')
                                ->whereHas(
                                    'organizationalUnit',
                                    fn (Builder $unit) => $unit->whereIn('department_id', $departmentIds),
                                );
                        });
                })->whereNot(fn (Builder $record) => $this->psOfficeOwned($record));
            })
                ->orWhereHas('task', fn (Builder $task) => $task->whereIn('department_id', $departmentIds))
                ->orWhereHas('routingTask', fn (Builder $task) => $task->whereIn('department_id', $departmentIds))
                ->orWhereHas('correspondence.recipients', fn (Builder $recipient) => $recipient
                    ->where('active', true)
                    ->where(fn (Builder $target) => $target
                        ->whereIn('correspondence_recipients.department_id', $departmentIds)
                        ->orWhere('correspondence_recipients.user_id', $user->id)));
        });
    }

    /**
     * The PS Office sits outside every department, so a record held there
     * under the Permanent Secretary is never departmental correspondence —
     * even where an older capture path wrote a stray department stamp onto
     * it. Records held there under a departmental supervisor keep their
     * department ownership.
     *
     * @param  Builder<MailRecord>  $query
     * @return Builder<MailRecord>
     */
    private function psOfficeOwned(Builder $query): Builder
    {
        return $query
            ->whereHas(
                'organizationalUnit',
                fn (Builder $unit) => $unit->whereNull('department_id')
                    ->where('name', 'Office of the Permanent Secretary'),
            )
            ->whereHas(
                'officeSupervisor',
                fn (Builder $supervisor) => $supervisor->where('role', Role::Ps->value),
            );
    }

    public function allowsMail(User $user, MailRecord $mail): bool
    {
        return $this->applyMail(MailRecord::query(), $user)
            ->whereKey($mail->id)
            ->exists();
    }
}
