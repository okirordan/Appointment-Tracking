<?php

namespace App\Services\Mail;

use App\Enums\Role;
use App\Models\Department;
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
 * Correspondence is confidential by default. The Permanent Secretary may
 * oversee all correspondence; a department head and that department's
 * secretary may see their department register. Everyone else must be a
 * direct recipient, assignee, active participant, or explicit grant holder.
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
        // The PS has explicit organisation-wide oversight. No other role,
        // capability, or organisational seniority bypasses this scope.
        if ($user->role === Role::Ps) {
            return $query;
        }

        $memberDepartmentIds = $this->departments->currentDepartmentIds($user);
        $custodianDepartmentIds = $this->custodianDepartmentIds($user, $memberDepartmentIds);
        $custodianOfficeIds = $user->role === Role::Secretary
            ? $this->targets->officeIdsFor($user)
            : [];
        $psOfficeSecretary = $user->role === Role::Secretary
            && $user->currentSecretaryAttachment?->supervisor?->role === Role::Ps;

        if ($psOfficeSecretary && $custodianOfficeIds === []) {
            // Legacy PS-secretary accounts predate unit-linked appointments.
            // Resolve only their OPS custodianship, never a department.
            $centralRegistryId = OrganizationalUnit::query()
                ->where('active', true)
                ->where(fn (Builder $office) => $office
                    ->where('code', 'OPS')
                    ->orWhere('name', 'Office of the Permanent Secretary'))
                ->value('id');
            if ($centralRegistryId !== null) {
                $custodianOfficeIds = [(int) $centralRegistryId];
            }
        }

        return $query->where(function (Builder $visible) use ($user, $custodianOfficeIds, $custodianDepartmentIds) {
            $visible
                ->where(function (Builder $owned) use ($user, $custodianOfficeIds, $custodianDepartmentIds) {
                    // Start from an impossible condition so optional office
                    // and department branches can be appended safely.
                    $owned->whereRaw('1 = 0');

                    if ($user->role === Role::Clerk) {
                        // Recording a letter is direct workflow involvement;
                        // the clerk does not inherit the whole PS register.
                        $owned->orWhere('mail_records.captured_by_user_id', $user->id);
                    }

                    if ($custodianDepartmentIds !== []) {
                        $owned
                            ->orWhere(function (Builder $department) use ($custodianDepartmentIds) {
                                $department
                                    ->whereIn('mail_records.department_id', $custodianDepartmentIds)
                                    ->whereNot(fn (Builder $record) => $this->psOfficeOwned($record));
                            })
                            ->orWhere(function (Builder $legacyDepartment) use ($custodianDepartmentIds) {
                                $legacyDepartment
                                    ->whereNull('mail_records.department_id')
                                    ->whereHas('organizationalUnit', fn (Builder $unit) => $unit
                                        ->whereIn('department_id', $custodianDepartmentIds));
                            });
                    }

                    if ($custodianOfficeIds !== []) {
                        $owned->orWhere(function (Builder $office) use ($custodianOfficeIds) {
                            // A department stamp is authoritative. This keeps
                            // an accidental office stamp from crossing the
                            // confidentiality boundary of a department.
                            $office->whereIn('mail_records.organizational_unit_id', $custodianOfficeIds)
                                ->where(fn (Builder $record) => $record
                                    ->whereNull('mail_records.department_id')
                                    ->orWhere(fn (Builder $legacyPs) => $this->psOfficeOwned($legacyPs)));
                        });
                    }
                })
                ->orWhereHas('correspondence.recipients', function (Builder $recipient) use ($user, $custodianOfficeIds, $custodianDepartmentIds) {
                    $recipient->where('active', true)->where(function (Builder $target) use ($user, $custodianOfficeIds, $custodianDepartmentIds) {
                        $target->where('user_id', $user->id);
                        if ($custodianOfficeIds !== []) {
                            $target->orWhereIn('organizational_unit_id', $custodianOfficeIds);
                        }
                        if ($custodianDepartmentIds !== []) {
                            $target->orWhereIn('department_id', $custodianDepartmentIds);
                        }
                    });
                })
                ->orWhereHas('correspondence.accessGrants', fn (Builder $grant) => $grant
                    ->where('user_id', $user->id)
                    ->whereNull('revoked_at'))
                ->orWhereHas('task', fn (Builder $task) => $this->applyTaskRecipient($task, $user, $custodianOfficeIds, $custodianDepartmentIds))
                ->orWhereHas('routingTask', fn (Builder $task) => $this->applyTaskRecipient($task, $user, $custodianOfficeIds, $custodianDepartmentIds));
        });
    }

    public function allows(User $user, MailRecord $mail): bool
    {
        return $this->apply(MailRecord::query(), $user)
            ->whereKey($mail->id)
            ->exists();
    }

    /**
     * Resolve only departments for which the user is a correspondence
     * custodian. A profile's department_id alone never grants visibility.
     *
     * @param  list<int>  $memberDepartmentIds
     * @return list<int>
     */
    private function custodianDepartmentIds(User $user, array $memberDepartmentIds): array
    {
        if ($user->role === Role::Secretary) {
            return $memberDepartmentIds;
        }

        $headedIds = Department::query()
            ->where('head_user_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Legacy commissioner accounts may predate an explicit head_user_id.
        // Once a head is recorded, only that named head is a custodian.
        if ($user->role === Role::Commissioner && $memberDepartmentIds !== []) {
            $headedIds = array_merge(
                $headedIds,
                Department::query()
                    ->whereIn('id', $memberDepartmentIds)
                    ->whereNull('head_user_id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            );
        }

        return array_values(array_unique($headedIds));
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
