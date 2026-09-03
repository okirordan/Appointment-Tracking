<?php

namespace App\Services;

use App\Enums\OrganizationalUnitType;
use App\Models\OrganizationalUnit;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationStructureService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, ?string $reason = null): OrganizationalUnit
    {
        $entity = DB::transaction(function () use ($data): OrganizationalUnit {
            $parent = filled($data['parent_id'] ?? null)
                ? OrganizationalUnit::query()->lockForUpdate()->findOrFail($data['parent_id'])
                : null;
            $this->assertIntentionalPlacement($parent, (bool) ($data['is_top_level'] ?? false));
            $this->assertPermittedParentType((string) $data['type'], $parent);
            $this->assertUniqueSiblingName((string) $data['name'], $parent?->id);

            $entity = OrganizationalUnit::create([
                ...$data,
                ...$this->legacyPlacement($parent),
            ]);
            $this->syncSecretary($entity, null, $entity->secretary_user_id);

            return $entity->fresh(['parent', 'head', 'secretary']);
        });

        $this->audit->log(
            'organization_structure',
            "Created organizational entity {$entity->name}",
            $actor,
            'OrganizationalUnit',
            $entity->id,
            ['after' => $entity->toArray(), 'reason' => $reason],
        );

        return $entity;
    }

    /** @param array<string, mixed> $data */
    public function update(OrganizationalUnit $entity, array $data, User $actor, ?string $reason = null): OrganizationalUnit
    {
        $before = $entity->toArray();
        $previousSecretaryId = $entity->secretary_user_id;

        $updated = DB::transaction(function () use ($entity, $data, $previousSecretaryId): OrganizationalUnit {
            $locked = OrganizationalUnit::query()->lockForUpdate()->findOrFail($entity->id);
            $parent = filled($data['parent_id'] ?? null)
                ? OrganizationalUnit::query()->lockForUpdate()->findOrFail($data['parent_id'])
                : null;
            $this->assertValidParent($locked, $parent, (bool) ($data['is_top_level'] ?? false));
            $this->assertPermittedParentType((string) $data['type'], $parent);
            $this->assertUniqueSiblingName((string) $data['name'], $parent?->id, $locked->id);
            $locked->update([...$data, ...$this->legacyPlacement($parent, $locked)]);
            $this->syncLegacyPlacementTree($locked);
            $this->syncSecretary($locked, $previousSecretaryId, $locked->secretary_user_id);

            return $locked->fresh(['parent', 'head', 'secretary']);
        });

        $this->audit->log(
            'organization_structure',
            "Updated organizational entity {$updated->name}",
            $actor,
            'OrganizationalUnit',
            $updated->id,
            ['before' => $before, 'after' => $updated->toArray(), 'reason' => $reason],
        );

        return $updated;
    }

    public function move(OrganizationalUnit $entity, ?int $parentId, bool $isTopLevel, User $actor, string $reason): OrganizationalUnit
    {
        $previousParentId = $entity->parent_id;

        $updated = DB::transaction(function () use ($entity, $parentId, $isTopLevel): OrganizationalUnit {
            $locked = OrganizationalUnit::query()->lockForUpdate()->findOrFail($entity->id);
            $parent = $parentId === null ? null : OrganizationalUnit::query()->lockForUpdate()->findOrFail($parentId);
            $this->assertValidParent($locked, $parent, $isTopLevel);
            $this->assertPermittedParentType($locked->type, $parent);
            $this->assertUniqueSiblingName($locked->name, $parent?->id, $locked->id);
            $locked->update([
                'parent_id' => $parent?->id,
                'is_top_level' => $isTopLevel,
                ...$this->legacyPlacement($parent, $locked),
            ]);
            $this->syncLegacyPlacementTree($locked);

            return $locked->fresh('parent');
        });

        $this->audit->log(
            'organization_structure',
            "Moved organizational entity {$updated->name}",
            $actor,
            'OrganizationalUnit',
            $updated->id,
            [
                'previous_parent_id' => $previousParentId,
                'new_parent_id' => $updated->parent_id,
                'reason' => $reason,
            ],
        );

        return $updated;
    }

    public function assertUniqueSiblingName(string $name, ?int $parentId, ?int $ignoreId = null): void
    {
        $duplicate = OrganizationalUnit::query()
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'An organizational entity with this name already exists under the selected parent.',
            ]);
        }
    }

    private function assertValidParent(OrganizationalUnit $entity, ?OrganizationalUnit $parent, bool $isTopLevel): void
    {
        $this->assertIntentionalPlacement($parent, $isTopLevel);
        if ($entity->type === OrganizationalUnitType::Ministry->value && $parent !== null) {
            throw ValidationException::withMessages(['parent_id' => 'The Ministry root cannot have a parent entity.']);
        }
        if ($parent === null) {
            return;
        }
        if ($parent->id === $entity->id) {
            throw ValidationException::withMessages(['parent_id' => 'An entity cannot be its own parent.']);
        }

        $visited = [];
        $cursor = $parent;
        while ($cursor !== null) {
            if ($cursor->id === $entity->id) {
                throw ValidationException::withMessages(['parent_id' => 'This move would create a circular organizational hierarchy.']);
            }
            if (isset($visited[$cursor->id])) {
                throw ValidationException::withMessages(['parent_id' => 'The selected parent belongs to an invalid circular hierarchy.']);
            }
            $visited[$cursor->id] = true;
            $cursor = $cursor->parent()->first();
        }
    }

    private function assertIntentionalPlacement(?OrganizationalUnit $parent, bool $isTopLevel): void
    {
        if ($parent === null && ! $isTopLevel) {
            throw ValidationException::withMessages([
                'parent_id' => 'Choose a parent entity or explicitly mark this as a top-level entity.',
            ]);
        }
        if ($parent !== null && $isTopLevel) {
            throw ValidationException::withMessages([
                'is_top_level' => 'An entity with a parent cannot also be marked as top level.',
            ]);
        }
    }

    private function assertPermittedParentType(string $childType, ?OrganizationalUnit $parent): void
    {
        if ($parent === null) {
            return;
        }

        $childIsExternal = $childType === OrganizationalUnitType::AffiliatedBody->value;
        $parentIsExternal = $parent->type === OrganizationalUnitType::AffiliatedBody->value;
        if ($childIsExternal !== $parentIsExternal) {
            throw ValidationException::withMessages([
                'parent_id' => 'Internal Ministry entities and affiliated or external bodies must remain in separate hierarchy branches.',
            ]);
        }
    }

    /** @return array{department_id: int|null, division_id: int|null} */
    private function legacyPlacement(?OrganizationalUnit $parent, ?OrganizationalUnit $entity = null): array
    {
        return [
            'department_id' => $entity?->type === 'department' && $entity->department_id !== null
                ? $entity->department_id
                : $parent?->department_id,
            'division_id' => $entity?->type === 'division' && $entity->division_id !== null
                ? $entity->division_id
                : $parent?->division_id,
        ];
    }

    private function syncSecretary(OrganizationalUnit $entity, ?int $previousSecretaryId, ?int $secretaryId): void
    {
        if ($previousSecretaryId !== null && $previousSecretaryId !== $secretaryId) {
            SecretaryOfficeAttachment::query()
                ->where('secretary_user_id', $previousSecretaryId)
                ->where('organizational_unit_id', $entity->id)
                ->where('active', true)
                ->update(['active' => false, 'ends_at' => now()]);
            User::query()
                ->whereKey($previousSecretaryId)
                ->where('organizational_unit_id', $entity->id)
                ->update(['organizational_unit_id' => null]);
        }
        if ($secretaryId === null) {
            return;
        }

        SecretaryOfficeAttachment::query()
            ->where('secretary_user_id', $secretaryId)
            ->where(fn ($attachment) => $attachment
                ->whereNull('organizational_unit_id')
                ->orWhere('organizational_unit_id', '!=', $entity->id))
            ->where('active', true)
            ->update(['active' => false, 'ends_at' => now()]);

        User::query()->whereKey($secretaryId)->lockForUpdate()->update([
            'organizational_unit_id' => $entity->id,
            'department_id' => $entity->department_id,
            'division_id' => $entity->division_id,
        ]);
    }

    private function syncLegacyPlacementTree(OrganizationalUnit $entity): void
    {
        User::query()->where('organizational_unit_id', $entity->id)->update([
            'department_id' => $entity->department_id,
            'division_id' => $entity->division_id,
        ]);

        foreach ($entity->children()->lockForUpdate()->get() as $child) {
            $child->update($this->legacyPlacement($entity, $child));
            $this->syncLegacyPlacementTree($child);
        }
    }
}
