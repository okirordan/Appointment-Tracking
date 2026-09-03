<?php

namespace App\Services;

use App\Enums\OrganizationalUnitType;
use App\Enums\Role as SystemRole;
use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StaffOrganizationalPlacementService
{
    /** @return list<string> */
    public static function assignableTypeValues(): array
    {
        return array_map(
            fn (OrganizationalUnitType $type): string => $type->value,
            OrganizationalUnitType::selectable(),
        );
    }

    /**
     * @return list<array{
     *     id: int,
     *     parent_id: int|null,
     *     name: string,
     *     type: string,
     *     type_label: string,
     *     path: string,
     *     department_entity_id: int|null,
     *     division_entity_id: int|null
     * }>
     */
    public function options(): array
    {
        $entities = OrganizationalUnit::query()
            ->get(['id', 'parent_id', 'name', 'type', 'active']);
        $byId = $entities->keyBy('id');

        return $entities
            ->where('active', true)
            ->whereIn('type', self::assignableTypeValues())
            ->map(fn (OrganizationalUnit $entity): array => [
                'id' => $entity->id,
                'parent_id' => $entity->parent_id,
                'name' => $entity->name,
                'type' => $entity->type,
                'type_label' => OrganizationalUnitType::tryFrom($entity->type)?->label() ?? 'Entity',
                'path' => $this->path($entity, $byId),
                'department_entity_id' => $this->ancestorId($entity, $byId, OrganizationalUnitType::Department),
                'division_entity_id' => $this->ancestorId($entity, $byId, OrganizationalUnitType::Division),
            ])
            ->sortBy(fn (array $entity): string => mb_strtolower($entity['path']))
            ->values()
            ->all();
    }

    public function assertAssignable(Role|string $role, ?OrganizationalUnit $entity): void
    {
        $roleName = $role instanceof Role ? $role->name : $role;

        if ($entity === null) {
            if ($roleName !== SystemRole::Sysadmin->value) {
                throw ValidationException::withMessages([
                    'organizational_unit_id' => 'Select the exact organizational entity where this staff member works.',
                ]);
            }

            return;
        }

        if (! in_array($entity->type, self::assignableTypeValues(), true) || ! $entity->active || $entity->trashed()) {
            throw ValidationException::withMessages([
                'organizational_unit_id' => 'Select an active internal organizational entity.',
            ]);
        }
    }

    public function synchronizeSecretaryAttachment(
        User $user,
        Role $role,
        ?OrganizationalUnit $entity,
        ?User $actor,
    ): void {
        $attachments = SecretaryOfficeAttachment::query()
            ->where('secretary_user_id', $user->id)
            ->where('active', true)
            ->lockForUpdate();

        if ($role->name !== SystemRole::Secretary->value) {
            $attachments->update([
                'active' => false,
                'ends_at' => now(),
                'ended_by_user_id' => $actor?->id,
            ]);

            return;
        }

        $attachments->current()->update([
            'organizational_unit_id' => $entity?->id,
        ]);
    }

    /** @param Collection<int, OrganizationalUnit> $byId */
    private function path(OrganizationalUnit $entity, Collection $byId): string
    {
        $segments = [];
        $visited = [];
        $cursor = $entity;

        while ($cursor !== null && ! isset($visited[$cursor->id])) {
            array_unshift($segments, $cursor->name);
            $visited[$cursor->id] = true;
            $cursor = $cursor->parent_id === null ? null : $byId->get($cursor->parent_id);
        }

        return implode(' › ', $segments);
    }

    /** @param Collection<int, OrganizationalUnit> $byId */
    private function ancestorId(
        OrganizationalUnit $entity,
        Collection $byId,
        OrganizationalUnitType $type,
    ): ?int {
        $visited = [];
        $cursor = $entity;

        while ($cursor !== null && ! isset($visited[$cursor->id])) {
            if ($cursor->type === $type->value) {
                return $cursor->id;
            }

            $visited[$cursor->id] = true;
            $cursor = $cursor->parent_id === null ? null : $byId->get($cursor->parent_id);
        }

        return null;
    }
}
