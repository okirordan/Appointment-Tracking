<?php

namespace App\Services\Mail;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\Task;
use App\Models\User;
use App\Services\DepartmentAccessService;
use App\Services\Tasks\AssignmentTargetService;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of truth for correspondence visibility.
 *
 * Organisational seniority and broad mail permissions never widen this
 * scope. A user must belong to the owning office/department or be an active
 * recipient, assignee, or explicit access-grant holder.
 */
class MailAccessScope
{
    public function __construct(
        private DepartmentAccessService $departments,
        private AssignmentTargetService $targets,
    ) {}

    /**
     * @param  Builder<MailRecord>  $query
     * @return Builder<MailRecord>
     */
    public function apply(Builder $query, User $user): Builder
    {
        $officeIds = $this->targets->officeIdsFor($user);
        $departmentIds = $this->departments->currentDepartmentIds($user);
        $psOfficeMember = $user->role === Role::Ps
            || $user->role === Role::Clerk
            || ($user->role === Role::Secretary
                && $user->currentSecretaryAttachment?->supervisor?->role === Role::Ps);
        if ($psOfficeMember && $officeIds === []) {
            // Legacy PS, central-registry, and PS-secretary accounts predate
            // unit-linked appointments. Resolve their actual OPS membership
            // without granting access to any department-owned record.
            $centralRegistryId = OrganizationalUnit::query()
                ->where('active', true)
                ->where(fn (Builder $office) => $office
                    ->where('code', 'OPS')
                    ->orWhere('name', 'Office of the Permanent Secretary'))
                ->value('id');
            if ($centralRegistryId !== null) {
                $officeIds = [(int) $centralRegistryId];
            }
        }

        return $query->where(function (Builder $visible) use ($user, $officeIds, $departmentIds) {
            $visible
                ->where(function (Builder $owned) use ($user, $officeIds, $departmentIds) {
                    // Start from an impossible condition so optional office
                    // and department branches can be appended safely.
                    $owned->whereRaw('1 = 0');

                    if ($departmentIds !== []) {
                        $owned
                            ->orWhere(function (Builder $department) use ($departmentIds) {
                                $department
                                    ->whereIn('mail_records.department_id', $departmentIds)
                                    ->whereNot(fn (Builder $record) => $this->psOfficeOwned($record));
                            })
                            ->orWhere(function (Builder $legacyDepartment) use ($departmentIds) {
                                $legacyDepartment
                                    ->whereNull('mail_records.department_id')
                                    ->whereHas('organizationalUnit', fn (Builder $unit) => $unit
                                        ->whereIn('department_id', $departmentIds));
                            });
                    }

                    if ($officeIds !== []) {
                        $owned->orWhere(function (Builder $office) use ($officeIds) {
                            // A department stamp is authoritative. This keeps
                            // an accidental PS-office unit stamp from exposing
                            // departmental correspondence to the PS Office.
                            $office->whereIn('mail_records.organizational_unit_id', $officeIds)
                                ->where(fn (Builder $record) => $record
                                    ->whereNull('mail_records.department_id')
                                    ->orWhere(fn (Builder $legacyPs) => $this->psOfficeOwned($legacyPs)));
                        });
                    }

                    // The named supervisor owns a non-departmental office
                    // record even when legacy position data has no unit link.
                    $owned->orWhere(function (Builder $supervised) use ($user) {
                        $supervised
                            ->where('mail_records.office_supervisor_user_id', $user->id)
                            ->where(fn (Builder $record) => $record
                                ->whereNull('mail_records.department_id')
                                ->orWhere(fn (Builder $legacyPs) => $this->psOfficeOwned($legacyPs)));
                    });
                })
                ->orWhereHas('correspondence.recipients', function (Builder $recipient) use ($user, $officeIds, $departmentIds) {
                    $recipient->where('active', true)->where(function (Builder $target) use ($user, $officeIds, $departmentIds) {
                        $target->where('user_id', $user->id);
                        if ($officeIds !== []) {
                            $target->orWhereIn('organizational_unit_id', $officeIds);
                        }
                        if ($departmentIds !== []) {
                            $target->orWhereIn('department_id', $departmentIds);
                        }
                    });
                })
                ->orWhereHas('correspondence.accessGrants', fn (Builder $grant) => $grant
                    ->where('user_id', $user->id)
                    ->whereNull('revoked_at'))
                ->orWhereHas('task', fn (Builder $task) => $this->applyTaskRecipient($task, $user, $officeIds, $departmentIds))
                ->orWhereHas('routingTask', fn (Builder $task) => $this->applyTaskRecipient($task, $user, $officeIds, $departmentIds));
        });
    }

    public function allows(User $user, MailRecord $mail): bool
    {
        return $this->apply(MailRecord::query(), $user)
            ->whereKey($mail->id)
            ->exists();
    }

    /**
     * @param  Builder<Task>  $task
     * @param  list<int>  $officeIds
     * @param  list<int>  $departmentIds
     */
    private function applyTaskRecipient(Builder $task, User $user, array $officeIds, array $departmentIds): void
    {
        $task->where(function (Builder $recipient) use ($user, $officeIds, $departmentIds) {
            $recipient
                ->whereIn('assigned_to_user_id', [$user->id])
                ->orWhere('current_assignee_user_id', $user->id)
                ->orWhere('responsible_user_id', $user->id)
                ->orWhere('current_reviewer_user_id', $user->id)
                ->orWhere('final_approver_user_id', $user->id)
                ->orWhereHas('participants', fn (Builder $participant) => $participant
                    ->where('user_id', $user->id)
                    ->where('active', true));

            if ($officeIds !== []) {
                $recipient->orWhere(fn (Builder $office) => $office
                    ->where('assignment_target_type', 'office')
                    ->whereIn('assigned_to_organizational_unit_id', $officeIds));
            }

            if ($departmentIds !== []) {
                $recipient->orWhere(fn (Builder $department) => $department
                    ->where('assignment_target_type', 'department')
                    ->whereIn('assigned_to_department_id', $departmentIds));
            }
        });
    }

    /** @param Builder<MailRecord> $query */
    private function psOfficeOwned(Builder $query): Builder
    {
        return $query
            ->whereHas('organizationalUnit', fn (Builder $unit) => $unit
                ->whereNull('department_id')
                ->where(fn (Builder $office) => $office
                    ->where('code', 'OPS')
                    ->orWhere('name', 'Office of the Permanent Secretary')))
            ->whereHas('officeSupervisor', fn (Builder $supervisor) => $supervisor
                ->where('role', Role::Ps->value));
    }
}
