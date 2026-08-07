<?php

namespace App\Services\Tasks;

use App\Models\Department;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AssignmentTargetService
{
    /** @return Builder<User> */
    public function eligibleUsers(): Builder
    {
        return User::query()
            ->where('active', true)
            ->where('locked', false)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('is_active', true));
    }

    /** @return Collection<int, User> */
    public function officeMembers(int $unitId): Collection
    {
        return $this->eligibleUsers()
            ->where(function (Builder $members) use ($unitId) {
                $members->whereHas(
                    'currentPositionAssignment.position',
                    fn (Builder $position) => $position->where('organizational_unit_id', $unitId),
                )->orWhereHas(
                    'currentSecretaryAttachment',
                    fn (Builder $attachment) => $attachment->where('organizational_unit_id', $unitId),
                );
            })
            ->with(['department', 'currentPositionAssignment.position.organizationalUnit'])
            ->orderBy('full_name')
            ->get();
    }

    /** @return Collection<int, User> */
    public function departmentMembers(int $departmentId): Collection
    {
        return $this->eligibleUsers()
            ->where(function (Builder $members) use ($departmentId) {
                $members->where('department_id', $departmentId)
                    ->orWhereHas(
                        'currentPositionAssignment.position.organizationalUnit',
                        fn (Builder $unit) => $unit->where('department_id', $departmentId),
                    )
                    ->orWhereHas(
                        'currentSecretaryAttachment.organizationalUnit',
                        fn (Builder $unit) => $unit->where('department_id', $departmentId),
                    );
            })
            ->with(['department', 'currentPositionAssignment.position.organizationalUnit'])
            ->orderBy('full_name')
            ->get();
    }

    /** @return list<int> */
    public function officeIdsFor(User $user): array
    {
        $user->loadMissing([
            'currentPositionAssignment.position',
            'currentSecretaryAttachment',
        ]);

        return collect([
            $user->currentPositionAssignment?->position?->organizational_unit_id,
            $user->currentSecretaryAttachment?->organizational_unit_id,
        ])->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return list<int> */
    public function departmentIdsFor(User $user): array
    {
        $user->loadMissing([
            'currentPositionAssignment.position.organizationalUnit',
            'currentSecretaryAttachment.organizationalUnit',
        ]);

        return collect([
            $user->department_id,
            $user->currentPositionAssignment?->position?->organizationalUnit?->department_id,
            $user->currentSecretaryAttachment?->organizationalUnit?->department_id,
        ])->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, users: Collection<int, User>, office: ?OrganizationalUnit, department: ?Department, label: string}
     */
    public function resolve(array $data): array
    {
        $type = (string) ($data['target_type'] ?? 'individual');
        $office = null;
        $department = null;

        if ($type === 'office') {
            $office = OrganizationalUnit::query()->where('active', true)->find($data['organizational_unit_id'] ?? null);
            if ($office === null) {
                throw ValidationException::withMessages(['organizational_unit_id' => 'Select an active receiving office.']);
            }
            $users = $this->officeMembers($office->id);
            $department = $office->department;
            $label = $office->name;
        } elseif ($type === 'department') {
            $department = Department::query()->where('active', true)->find($data['target_department_id'] ?? null);
            if ($department === null) {
                throw ValidationException::withMessages(['target_department_id' => 'Select an active receiving department.']);
            }
            $users = $this->departmentMembers($department->id);
            $label = $department->name;
        } else {
            $ids = collect($data['assigned_to_user_ids'] ?? [])
                ->when(isset($data['assigned_to_user_id']), fn (Collection $ids) => $ids->push((int) $data['assigned_to_user_id']))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values();
            $found = $this->eligibleUsers()->with('department')->whereKey($ids)->get()->keyBy('id');
            $users = $ids->map(fn (int $id) => $found->get($id))->filter()->values();
            if ($users->isEmpty() || $users->count() !== $ids->count()) {
                throw ValidationException::withMessages(['assigned_to_user_ids' => 'One or more selected recipients are no longer eligible.']);
            }
            $type = $users->count() > 1 ? 'multiple' : 'individual';
            $department = $users->first()?->department;
            $label = $users->pluck('full_name')->implode(', ');
        }

        return compact('type', 'users', 'office', 'department', 'label');
    }

    public function userReceives(User $user, string $targetType, ?int $officeId, ?int $departmentId): bool
    {
        return match ($targetType) {
            'office' => $officeId !== null && in_array($officeId, $this->officeIdsFor($user), true),
            'department' => $departmentId !== null && in_array($departmentId, $this->departmentIdsFor($user), true),
            default => false,
        };
    }
}
