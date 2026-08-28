<?php

namespace App\Services;

use App\Enums\Role as SystemRole;
use App\Models\Position;
use App\Models\User;
use App\Models\UserPosition;
use App\Models\UserPositionChange;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserPositionService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Change a user's organizational position without replacing their account.
     *
     * The position's configured role is the source of truth for dashboard and
     * permissions, while the exact position title remains the official title.
     *
     * @param  array{
     *     supervisor_user_id?: int|null,
     *     is_acting?: bool,
     *     acting_for_user_id?: int|null,
     *     starts_at?: CarbonInterface|string|null,
     *     ends_at?: CarbonInterface|string|null,
     *     effective_date?: CarbonInterface|string|null,
     *     reason?: string|null
     * }  $options
     */
    public function change(User $user, Position $position, ?User $actor, array $options = []): UserPosition
    {
        $position->loadMissing(['role', 'organizationalUnit']);
        $systemRole = SystemRole::tryFrom((string) $position->role?->name);

        if ($position->role === null || ! $position->role->is_active || $systemRole === null) {
            throw ValidationException::withMessages([
                'position_id' => 'This position must be linked to an active system role before it can be assigned.',
            ]);
        }

        $effectiveAt = isset($options['effective_date'])
            ? Carbon::parse($options['effective_date'])->startOfDay()
            : (isset($options['starts_at']) ? Carbon::parse($options['starts_at']) : now());

        $result = DB::transaction(function () use ($user, $position, $actor, $options, $effectiveAt, $systemRole) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $oldAssignment = UserPosition::query()
                ->where('user_id', $lockedUser->id)
                ->where('is_primary', true)
                ->where('active', true)
                ->with('position')
                ->latest('id')
                ->first();
            $oldRole = $lockedUser->permissionRole();
            $oldTitle = $lockedUser->title;

            if ($oldAssignment?->position_id === $position->id) {
                $appointment = $oldAssignment;
                $appointment->update([
                    'supervisor_user_id' => $options['supervisor_user_id'] ?? null,
                    'acting_for_user_id' => $options['acting_for_user_id'] ?? null,
                    'is_acting' => (bool) ($options['is_acting'] ?? false),
                    'starts_at' => $options['starts_at'] ?? $appointment->starts_at ?? $effectiveAt,
                    'ends_at' => $options['ends_at'] ?? null,
                    'active' => true,
                ]);
            } else {
                UserPosition::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('is_primary', true)
                    ->where('active', true)
                    ->update(['active' => false, 'ends_at' => $effectiveAt]);

                $appointment = UserPosition::create([
                    'user_id' => $lockedUser->id,
                    'position_id' => $position->id,
                    'supervisor_user_id' => $options['supervisor_user_id'] ?? null,
                    'acting_for_user_id' => $options['acting_for_user_id'] ?? null,
                    'is_primary' => true,
                    'is_acting' => (bool) ($options['is_acting'] ?? false),
                    'starts_at' => $options['starts_at'] ?? $effectiveAt,
                    'ends_at' => $options['ends_at'] ?? null,
                    'active' => true,
                ]);
            }

            $unit = $position->organizationalUnit;
            $lockedUser->forceFill([
                'title' => $position->title,
                'role' => $systemRole->value,
                'department_id' => $unit?->department_id,
                'division_id' => $unit?->division_id,
                'organizational_unit_id' => $unit?->id,
                'supervisor_user_id' => $options['supervisor_user_id'] ?? null,
            ])->save();
            $lockedUser->syncRoles([$position->role]);
            $lockedUser->unsetRelation('roles');
            $lockedUser->unsetRelation('currentPositionAssignment');

            if (
                $oldAssignment?->position_id !== $position->id
                || $oldRole?->id !== $position->role->id
                || $oldTitle !== $position->title
            ) {
                UserPositionChange::create([
                    'user_id' => $lockedUser->id,
                    'previous_position_id' => $oldAssignment?->position_id,
                    'new_position_id' => $position->id,
                    'previous_role_id' => $oldRole?->id,
                    'new_role_id' => $position->role->id,
                    'previous_title' => $oldTitle,
                    'new_title' => $position->title,
                    'effective_date' => $effectiveAt->toDateString(),
                    'changed_at' => now(),
                    'changed_by_user_id' => $actor?->id,
                    'reason' => $options['reason'] ?? null,
                ]);
            }

            return [$appointment, $lockedUser, $oldAssignment, $oldRole, $oldTitle];
        });

        [$appointment, $changedUser, $oldAssignment, $oldRole, $oldTitle] = $result;
        $this->audit->log(
            'user',
            "Changed organizational position for {$changedUser->username}",
            $actor,
            'User',
            $changedUser->id,
            [
                'previous_position_id' => $oldAssignment?->position_id,
                'new_position_id' => $position->id,
                'previous_role' => $oldRole?->name,
                'new_role' => $position->role->name,
                'previous_title' => $oldTitle,
                'new_title' => $position->title,
                'effective_date' => $effectiveAt->toDateString(),
                'reason' => $options['reason'] ?? null,
            ],
        );

        return $appointment;
    }
}
