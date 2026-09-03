<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationalUnitType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\OrganizationStructureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationStructureController extends Controller
{
    public function __construct(private OrganizationStructureService $structure) {}

    public function index(): Response
    {
        $entities = OrganizationalUnit::query()
            ->with(['parent:id,name', 'head:id,full_name', 'secretary:id,full_name'])
            ->withCount(['children', 'users'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/hierarchy/index', [
            'entities' => $entities->map(function (OrganizationalUnit $entity): array {
                $type = OrganizationalUnitType::tryFrom($entity->type);

                return [
                    'id' => $entity->id,
                    'name' => $entity->name,
                    'code' => $entity->code,
                    'type' => $entity->type,
                    'type_label' => $type?->label() ?? Str::headline($entity->type),
                    'parent_id' => $entity->parent_id,
                    'parent_name' => $entity->parent?->name,
                    'description' => $entity->description,
                    'head_user_id' => $entity->head_user_id,
                    'head_name' => $entity->head?->full_name,
                    'secretary_user_id' => $entity->secretary_user_id,
                    'secretary_name' => $entity->secretary?->full_name,
                    'active' => $entity->active,
                    'is_top_level' => $entity->is_top_level,
                    'sort_order' => $entity->sort_order,
                    'children_count' => $entity->children_count,
                    'users_count' => $entity->users_count,
                ];
            }),
            'entityTypes' => collect(OrganizationalUnitType::selectable())
                ->map(fn (OrganizationalUnitType $type) => ['value' => $type->value, 'label' => $type->label()]),
            'headOptions' => User::query()
                ->where('active', true)
                ->where('role', '!=', Role::Secretary->value)
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'title'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->full_name, 'title' => $user->title]),
            'secretaryOptions' => User::query()
                ->where('active', true)
                ->where('role', Role::Secretary->value)
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'title'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->full_name, 'title' => $user->title]),
            'summary' => [
                'total' => $entities->count(),
                'active' => $entities->where('active', true)->count(),
                'top_level' => $entities->where('is_top_level', true)->count(),
                'external' => $entities->where('type', OrganizationalUnitType::AffiliatedBody->value)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $reason] = $this->entityData($request);
        $this->structure->assertUniqueSiblingName($data['name'], $data['parent_id']);
        $entity = $this->structure->create($data, $request->user(), $reason);

        return back()->with('success', "{$entity->name} was added to the organization structure.");
    }

    public function update(Request $request, OrganizationalUnit $entity): RedirectResponse
    {
        [$data, $reason] = $this->entityData($request, $entity);
        $this->structure->assertUniqueSiblingName($data['name'], $data['parent_id'], $entity->id);
        $updated = $this->structure->update($entity, $data, $request->user(), $reason);

        return back()->with('success', "{$updated->name} was updated.");
    }

    public function move(Request $request, OrganizationalUnit $entity): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')->whereNull('deleted_at')->where('active', true)],
            'is_top_level' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->structure->move(
            $entity,
            filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null,
            (bool) $data['is_top_level'],
            $request->user(),
            $data['reason'],
        );

        return back()->with('success', 'The entity was moved and the change was recorded in the audit trail.');
    }

    /** @return array{0: array<string, mixed>, 1: ?string} */
    private function entityData(Request $request, ?OrganizationalUnit $entity = null): array
    {
        $types = collect(OrganizationalUnitType::selectable())->map->value;
        if ($entity !== null) {
            $types->push($entity->type);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('organizational_units', 'code')->ignore($entity)],
            'type' => ['required', Rule::in($types->unique()->all())],
            'parent_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')->whereNull('deleted_at')->where('active', true)],
            'description' => ['nullable', 'string', 'max:5000'],
            'head_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true)],
            'secretary_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true)->where('role', Role::Secretary->value),
                Rule::unique('organizational_units', 'secretary_user_id')->ignore($entity),
                'different:head_user_id',
            ],
            'is_top_level' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($entity !== null && (int) ($data['parent_id'] ?? 0) === $entity->id) {
            throw ValidationException::withMessages(['parent_id' => 'An entity cannot be its own parent.']);
        }
        if ($entity?->type === OrganizationalUnitType::Ministry->value
            && ((int) ($data['parent_id'] ?? 0) !== (int) ($entity->parent_id ?? 0)
                || $data['type'] !== OrganizationalUnitType::Ministry->value)) {
            throw ValidationException::withMessages([
                'parent_id' => 'The Ministry root cannot be moved or converted to another entity type.',
            ]);
        }
        if ($entity?->type === OrganizationalUnitType::AffiliatedBody->value
            && $data['type'] !== OrganizationalUnitType::AffiliatedBody->value) {
            throw ValidationException::withMessages([
                'type' => 'Affiliated and external bodies must remain outside the internal Ministry entity types.',
            ]);
        }

        $parent = filled($data['parent_id'] ?? null)
            ? OrganizationalUnit::query()->find($data['parent_id'])
            : null;
        if ($parent?->type === OrganizationalUnitType::AffiliatedBody->value
            && $data['type'] !== OrganizationalUnitType::AffiliatedBody->value) {
            throw ValidationException::withMessages([
                'parent_id' => 'Internal Ministry entities cannot be placed under an affiliated or external body.',
            ]);
        }

        $reason = $data['reason'] ?? null;
        unset($data['reason']);
        $data['name'] = trim($data['name']);
        $data['code'] = filled($data['code'] ?? null) ? mb_strtoupper(trim($data['code'])) : null;
        $data['parent_id'] = filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null;

        return [$data, $reason];
    }
}
