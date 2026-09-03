<?php

namespace App\Services;

use App\Enums\OrganizationalUnitType;
use App\Enums\Role;
use App\Models\Department;
use App\Models\OrganizationalUnit;
use App\Models\User;

class OrganizationalScopeService
{
    public function __construct(private DepartmentAccessService $departments) {}

    public function primaryUnit(User $user): ?OrganizationalUnit
    {
        $user->loadMissing([
            'currentSecretaryAttachment.organizationalUnit',
            'currentPositionAssignment.position.organizationalUnit',
            'organizationalUnit',
        ]);

        $unit = $user->currentSecretaryAttachment?->organizationalUnit
            ?? $user->organizationalUnit
            ?? $user->currentPositionAssignment?->position?->organizationalUnit;

        return $unit?->type === OrganizationalUnitType::AffiliatedBody->value ? null : $unit;
    }

    /** @return list<int> */
    public function unitIds(User $user): array
    {
        $primaryUnit = $this->primaryUnit($user);
        $unitIds = collect($primaryUnit === null ? [] : [(int) $primaryUnit->id]);

        if (
            $unitIds->isEmpty()
            && $user->role === Role::Secretary
            && $user->currentSecretaryAttachment?->supervisor?->role === Role::Ps
        ) {
            $centralRegistryId = OrganizationalUnit::query()
                ->where('active', true)
                ->where(fn ($office) => $office
                    ->where('code', 'OPS')
                    ->orWhere('name', 'Office of the Permanent Secretary'))
                ->value('id');
            if ($centralRegistryId !== null) {
                $unitIds->push((int) $centralRegistryId);
            }
        }

        return $unitIds->all();
    }

    /** @return list<int> */
    public function divisionIds(User $user): array
    {
        $units = OrganizationalUnit::query()
            ->whereKey($this->unitIds($user))
            ->whereNotNull('division_id')
            ->pluck('division_id')
            ->map(fn ($id) => (int) $id);

        if ($units->isEmpty() && $user->division_id !== null && $this->primaryUnit($user) === null) {
            $units->push((int) $user->division_id);
        }

        return $units->unique()->values()->all();
    }

    public function hasDepartmentWideCustody(User $user): bool
    {
        if ($user->role !== Role::Secretary) {
            return false;
        }

        $unit = $this->primaryUnit($user);
        if ($unit !== null) {
            // A department node is itself the department register. Child
            // divisions and units remain exact-entity scopes.
            return $unit->department_id !== null
                && $unit->division_id === null
                && $unit->type === OrganizationalUnitType::Department->value;
        }

        return $user->department_id !== null;
    }

    /** @return list<int> */
    public function recipientDepartmentIds(User $user): array
    {
        if ($this->hasDepartmentWideCustody($user)) {
            return array_values(array_unique(array_map('intval', array_filter([
                $this->primaryUnit($user)?->department_id,
                $user->department_id,
            ]))));
        }

        if ($user->role === Role::Commissioner) {
            return $this->departments->currentDepartmentIds($user);
        }

        return Department::query()
            ->where('head_user_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isUnitScoped(User $user): bool
    {
        return $this->primaryUnit($user) !== null && ! $this->hasDepartmentWideCustody($user);
    }
}
