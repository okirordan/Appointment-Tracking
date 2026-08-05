<?php

namespace App\Services;

use App\Enums\Role as SystemRole;
use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use App\Models\UserPositionChange;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SecretaryAttachmentService
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
        private SecretaryAuthorityService $authority,
    ) {}

    /** @param list<string> $permissions */
    public function assign(
        User $secretary,
        User $supervisor,
        ?OrganizationalUnit $unit,
        string $officialTitle,
        Carbon $startsAt,
        ?Carbon $endsAt,
        bool $delegatedActionsPermitted,
        array $permissions,
        ?User $actor,
        ?string $reason,
    ): SecretaryOfficeAttachment {
        $unknownPermissions = array_diff($permissions, array_keys($this->authority->availablePermissions()));
        if ($unknownPermissions !== []) {
            throw ValidationException::withMessages([
                'delegated_permissions' => 'One or more delegated permissions are not permitted for secretary attachments.',
            ]);
        }
        if (! $delegatedActionsPermitted) {
            $permissions = [];
        }

        $result = DB::transaction(function () use (
            $secretary,
            $supervisor,
            $unit,
            $officialTitle,
            $startsAt,
            $endsAt,
            $delegatedActionsPermitted,
            $permissions,
            $actor,
            $reason,
        ) {
            $locked = User::query()->lockForUpdate()->findOrFail($secretary->id);
            $previous = SecretaryOfficeAttachment::query()
                ->where('secretary_user_id', $locked->id)
                ->where('active', true)
                ->with(['supervisor', 'organizationalUnit'])
                ->latest('id')
                ->first();
            // An office may have several active secretaries. Reassigning this
            // person ends only their own current attachment; it must never
            // displace colleagues who share the same supported office.
            $displaced = SecretaryOfficeAttachment::query()
                ->where('active', true)
                ->where('secretary_user_id', $locked->id)
                ->with('secretary')
                ->get();
            $oldRole = $locked->permissionRole();
            $oldTitle = $locked->title;

            SecretaryOfficeAttachment::query()
                ->whereKey($displaced->pluck('id'))
                ->update([
                    'active' => false,
                    'ends_at' => $startsAt,
                    'ended_by_user_id' => $actor?->id,
                    'updated_at' => now(),
                ]);

            $attachment = SecretaryOfficeAttachment::create([
                'secretary_user_id' => $locked->id,
                'supervisor_user_id' => $supervisor->id,
                'organizational_unit_id' => $unit?->id,
                'official_job_title' => $officialTitle,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'delegated_actions_permitted' => $delegatedActionsPermitted,
                'delegated_permissions' => array_values($permissions),
                'active' => true,
                'created_by_user_id' => $actor?->id,
                'reason' => $reason,
            ]);

            $secretaryRole = Role::where('name', SystemRole::Secretary->value)->where('is_active', true)->firstOrFail();
            $effectiveUnit = $unit ?? $supervisor->currentPositionAssignment?->position?->organizationalUnit;
            $locked->forceFill([
                'title' => $officialTitle,
                'role' => SystemRole::Secretary->value,
                'supervisor_user_id' => $supervisor->id,
                'department_id' => $effectiveUnit?->department_id ?? $supervisor->department_id,
                'division_id' => $effectiveUnit?->division_id ?? $supervisor->division_id,
            ])->save();
            $locked->syncRoles([$secretaryRole]);
            $locked->unsetRelation('roles');
            $locked->unsetRelation('currentSecretaryAttachment');

            UserPositionChange::create([
                'user_id' => $locked->id,
                'previous_position_id' => null,
                'new_position_id' => null,
                'previous_role_id' => $oldRole?->id,
                'new_role_id' => $secretaryRole->id,
                'previous_title' => $oldTitle,
                'new_title' => $officialTitle,
                'effective_date' => $startsAt->toDateString(),
                'changed_at' => now(),
                'changed_by_user_id' => $actor?->id,
                'reason' => $reason,
            ]);

            return [$attachment, $previous, $oldTitle, $locked, $displaced];
        });

        [$attachment, $previous, $oldTitle, $changedUser, $displaced] = $result;
        $this->audit->log(
            'user',
            "Updated secretary office attachment for {$changedUser->username}",
            $actor,
            'SecretaryOfficeAttachment',
            $attachment->id,
            [
                'previous_assignment' => $previous === null ? null : [
                    'supervisor' => $previous->supervisor?->full_name,
                    'office' => $previous->organizationalUnit?->name,
                    'permissions' => $previous->delegated_permissions ?? [],
                ],
                'new_assignment' => [
                    'supervisor' => $supervisor->full_name,
                    'office' => $unit?->name,
                    'permissions' => $permissions,
                ],
                'access_removed_from' => $displaced
                    ->where('secretary_user_id', '!=', $changedUser->id)
                    ->pluck('secretary.full_name')
                    ->filter()
                    ->values()
                    ->all(),
                'previous_title' => $oldTitle,
                'new_title' => $officialTitle,
                'effective_date' => $startsAt->toDateString(),
                'reason' => $reason,
            ],
        );
        $this->notifications->notify(
            $changedUser,
            'office_attachment',
            "Your office attachment is now {$supervisor->full_name}'s office",
            $unit?->name ?? $supervisor->title,
        );
        foreach ($displaced->where('secretary_user_id', '!=', $changedUser->id) as $formerAttachment) {
            if ($formerAttachment->secretary !== null) {
                $this->notifications->notify(
                    $formerAttachment->secretary,
                    'office_attachment',
                    'Your previous secretary office attachment has ended',
                    $unit?->name ?? $supervisor->title,
                );
            }
        }

        return $attachment;
    }

    public function end(SecretaryOfficeAttachment $attachment, ?User $actor, string $reason): void
    {
        $ended = DB::transaction(function () use ($attachment, $actor, $reason) {
            $locked = SecretaryOfficeAttachment::query()
                ->lockForUpdate()
                ->with(['secretary', 'supervisor', 'organizationalUnit'])
                ->findOrFail($attachment->id);

            if (! $locked->active) {
                return null;
            }

            $locked->update([
                'active' => false,
                'ends_at' => now(),
                'ended_by_user_id' => $actor?->id,
                'reason' => $reason,
            ]);

            return $locked;
        });

        if ($ended === null) {
            return;
        }

        $this->audit->log(
            'user',
            "Ended secretary office attachment for {$ended->secretary?->username}",
            $actor,
            'SecretaryOfficeAttachment',
            $ended->id,
            [
                'secretary_user_id' => $ended->secretary_user_id,
                'supervisor_user_id' => $ended->supervisor_user_id,
                'organizational_unit_id' => $ended->organizational_unit_id,
                'ended_at' => $ended->ends_at?->toIso8601String(),
                'reason' => $reason,
            ],
        );

        if ($ended->secretary !== null) {
            $this->notifications->notify(
                $ended->secretary,
                'office_attachment',
                'Your secretary office attachment has ended',
                $ended->organizationalUnit?->name ?? $ended->supervisor?->title,
            );
        }
    }
}
