<?php

namespace App\Services\Mail;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\RecipientAlias;
use App\Models\User;
use App\Services\DepartmentAccessService;
use App\Services\SecretaryAuthorityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RecipientSearchService
{
    public function __construct(
        private SecretaryAuthorityService $secretaryAuthority,
        private DepartmentAccessService $departments,
    ) {}

    /**
     * @return Builder<User>
     */
    public function assignableUsers(User $actor): Builder
    {
        $query = User::query()
            ->where('active', true)
            ->where('locked', false)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('is_active', true));

        if ($actor->role === Role::Commissioner) {
            $departmentIds = $this->departments->currentDepartmentIds($actor);

            return $departmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('department_id', $departmentIds);
        }

        if ($actor->role !== Role::Secretary) {
            return $query;
        }

        $attachment = $this->secretaryAuthority->attachment($actor);
        if (! $this->secretaryAuthority->allows($actor, 'mail.assign')) {
            return $query->whereRaw('1 = 0');
        }

        if ($attachment?->supervisor?->role === Role::Ps) {
            return $query;
        }

        $unit = $attachment?->organizationalUnit;
        $departmentId = $unit?->department_id ?? $attachment?->supervisor?->department_id ?? $actor->department_id;
        $divisionId = $unit?->division_id ?? $attachment?->supervisor?->division_id;

        if ($divisionId !== null) {
            return $query->where(function (Builder $scope) use ($divisionId) {
                $scope->where('division_id', $divisionId)
                    ->orWhereHas(
                        'currentPositionAssignment.position.organizationalUnit',
                        fn (Builder $unitQuery) => $unitQuery->where('division_id', $divisionId),
                    );
            });
        }

        if ($departmentId !== null) {
            return $query->where('department_id', $departmentId);
        }

        return $query->where(function (Builder $scope) use ($attachment, $actor) {
            $supervisorId = $attachment?->supervisor_user_id ?? $actor->supervisor_user_id;
            $scope->whereKey($supervisorId ?? $actor->id)
                ->when($supervisorId !== null, fn (Builder $users) => $users->orWhere('supervisor_user_id', $supervisorId));
        });
    }

    public function isAssignable(User $actor, User $recipient): bool
    {
        return $this->assignableUsers($actor)->whereKey($recipient->id)->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(User $actor, string $term, int $limit = 12): array
    {
        $term = trim($term);
        $normalized = RecipientAlias::normalize($term);

        if (mb_strlen($term) < 2 || mb_strlen($normalized) < 2) {
            return [];
        }

        $tokens = collect(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($term))) ?: [])
            ->filter(fn (string $token) => mb_strlen($token) >= 2)
            ->unique()
            ->values();

        $aliases = RecipientAlias::query()
            ->where('active', true)
            ->where('normalized_alias', 'like', '%'.$this->escapeLike($normalized).'%')
            ->orderByRaw('normalized_alias = ? desc', [$normalized])
            ->orderByRaw('LENGTH(normalized_alias)')
            ->limit(30)
            ->get();

        $exactAliasMatch = $aliases->contains(fn (RecipientAlias $alias) => $alias->normalized_alias === $normalized);
        if ($exactAliasMatch) {
            $aliases = $aliases->where('normalized_alias', $normalized)->values();
        }

        $targets = $this->aliasTargets($aliases);
        $users = $this->assignableUsers($actor)
            ->with([
                'department:id,name,code,head_user_id',
                'division:id,name,code',
                'currentPositionAssignment.position:id,organizational_unit_id,title',
                'currentPositionAssignment.position.organizationalUnit:id,department_id,division_id,type,name,code',
                'currentPositionAssignment.position.organizationalUnit.division:id,name,code',
            ])
            ->where(function (Builder $matches) use ($tokens, $targets, $exactAliasMatch) {
                if ($tokens->isNotEmpty() && ! $exactAliasMatch) {
                    $matches->where(function (Builder $direct) use ($tokens) {
                        foreach ($tokens as $token) {
                            $like = '%'.$this->escapeLike($token).'%';
                            $direct->where(function (Builder $part) use ($like) {
                                $part->where('full_name', 'like', $like)
                                    ->orWhere('username', 'like', $like)
                                    ->orWhere('employee_number', 'like', $like)
                                    ->orWhere('title', 'like', $like)
                                    ->orWhereHas('department', fn (Builder $department) => $department
                                        ->where('name', 'like', $like)->orWhere('code', 'like', $like))
                                    ->orWhereHas('division', fn (Builder $division) => $division
                                        ->where('name', 'like', $like)->orWhere('code', 'like', $like))
                                    ->orWhereHas('currentPositionAssignment.position', fn (Builder $position) => $position
                                        ->where('title', 'like', $like)
                                        ->orWhereHas('organizationalUnit', fn (Builder $unit) => $unit
                                            ->where('name', 'like', $like)
                                            ->orWhere('code', 'like', $like)
                                            ->orWhereHas('division', fn (Builder $division) => $division
                                                ->where('name', 'like', $like)->orWhere('code', 'like', $like))));
                            });
                        }
                    });
                }

                $method = $tokens->isNotEmpty() && ! $exactAliasMatch ? 'orWhere' : 'where';
                $matches->{$method}(function (Builder $aliasQuery) use ($targets) {
                    $aliasQuery->when($targets[User::class] !== [], fn (Builder $query) => $query->orWhereIn('users.id', $targets[User::class]))
                        ->when($targets[Department::class] !== [], fn (Builder $query) => $query->orWhereIn('department_id', $targets[Department::class]))
                        ->when($targets[Division::class] !== [], fn (Builder $query) => $query
                            ->orWhereIn('division_id', $targets[Division::class])
                            ->orWhereHas('currentPositionAssignment.position.organizationalUnit', fn (Builder $unit) => $unit->whereIn('division_id', $targets[Division::class])))
                        ->when($targets[Position::class] !== [], fn (Builder $query) => $query
                            ->orWhereHas('currentPositionAssignment', fn (Builder $assignment) => $assignment->whereIn('position_id', $targets[Position::class])))
                        ->when($targets[OrganizationalUnit::class] !== [], fn (Builder $query) => $query
                            ->orWhereHas('currentPositionAssignment.position', fn (Builder $position) => $position->whereIn('organizational_unit_id', $targets[OrganizationalUnit::class])));
                });
            })
            ->limit(100)
            ->get();
        $displayAliases = $this->applicableAliases($users);

        return $users
            ->unique('id')
            ->map(fn (User $user) => $this->result($user, $aliases, $displayAliases, $normalized))
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->map(fn (array $result) => collect($result)->except('score')->all())
            ->all();
    }

    /** @return array<class-string, list<int>> */
    private function aliasTargets(Collection $aliases): array
    {
        $targets = collect(RecipientAlias::TARGET_TYPES)->values()->mapWithKeys(fn (string $class) => [$class => []])->all();

        foreach ($aliases as $alias) {
            if (array_key_exists($alias->target_type, $targets)) {
                $targets[$alias->target_type][] = (int) $alias->target_id;
            }
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $targets);
    }

    private function applicableAliases(Collection $users): Collection
    {
        $userIds = $users->pluck('id')->all();
        $departmentIds = $users->pluck('department_id')->filter()->unique()->values()->all();
        $divisionIds = $users->map(fn (User $user) => $user->currentPositionAssignment?->position?->organizationalUnit?->division_id ?? $user->division_id)->filter()->unique()->values()->all();
        $positionIds = $users->map(fn (User $user) => $user->currentPositionAssignment?->position_id)->filter()->unique()->values()->all();
        $unitIds = $users->map(fn (User $user) => $user->currentPositionAssignment?->position?->organizational_unit_id)->filter()->unique()->values()->all();

        return RecipientAlias::query()->where('active', true)->where(function (Builder $query) use ($userIds, $departmentIds, $divisionIds, $positionIds, $unitIds) {
            $query->where(fn (Builder $target) => $target->where('target_type', User::class)->whereIn('target_id', $userIds))
                ->orWhere(fn (Builder $target) => $target->where('target_type', Department::class)->whereIn('target_id', $departmentIds))
                ->orWhere(fn (Builder $target) => $target->where('target_type', Division::class)->whereIn('target_id', $divisionIds))
                ->orWhere(fn (Builder $target) => $target->where('target_type', Position::class)->whereIn('target_id', $positionIds))
                ->orWhere(fn (Builder $target) => $target->where('target_type', OrganizationalUnit::class)->whereIn('target_id', $unitIds));
        })->get();
    }

    /** @return array<string, mixed> */
    private function result(User $user, Collection $matchedAliases, Collection $displayAliases, string $normalized): array
    {
        $assignment = $user->currentPositionAssignment;
        $position = $assignment?->position;
        $unit = $position?->organizationalUnit;
        $matchingAliases = $matchedAliases->filter(fn (RecipientAlias $alias) => $this->aliasMatches($alias, $user));
        $bestAlias = $matchingAliases->sortByDesc(fn (RecipientAlias $alias) => $alias->normalized_alias === $normalized ? 2 : 1)->first();
        $displayAlias = $bestAlias ?? $displayAliases
            ->filter(fn (RecipientAlias $alias) => $this->aliasMatches($alias, $user))
            ->sortByDesc(fn (RecipientAlias $alias) => match ($alias->target_type) {
                User::class => 5,
                Position::class => 4,
                OrganizationalUnit::class => 3,
                Division::class => 2,
                Department::class => 1,
                default => 0,
            })
            ->first();
        $fields = [
            'name' => RecipientAlias::normalize($user->full_name),
            'username' => RecipientAlias::normalize($user->username),
            'staff' => RecipientAlias::normalize((string) $user->employee_number),
            'title' => RecipientAlias::normalize($position?->title ?? (string) $user->title),
            'department' => RecipientAlias::normalize($user->department?->name ?? ''),
            'department_code' => RecipientAlias::normalize($user->department?->code ?? ''),
            'division' => RecipientAlias::normalize($unit?->division?->name ?? $user->division?->name ?? ''),
            'unit' => RecipientAlias::normalize($unit?->name ?? ''),
        ];

        $score = match (true) {
            $bestAlias?->normalized_alias === $normalized => 1000,
            $fields['name'] === $normalized => 960,
            in_array($normalized, [$fields['username'], $fields['staff']], true) => 940,
            $fields['title'] === $normalized => 900,
            $bestAlias !== null => 860,
            str_starts_with($fields['name'], $normalized) => 820,
            in_array($normalized, [$fields['department'], $fields['department_code'], $fields['division'], $fields['unit']], true) => 780,
            default => 600,
        };

        if ($bestAlias?->target_type === Department::class && $user->department?->head_user_id === $user->id) {
            $score += 30;
        }

        $matchedType = $bestAlias === null
            ? $this->directMatchType($fields, $normalized, $unit?->type)
            : RecipientAlias::targetKey($bestAlias->target_type);

        return [
            'id' => $user->id,
            'recipient_type' => $matchedType,
            'name' => $user->full_name,
            'title' => $position?->title ?? $user->title,
            'department_id' => $user->department_id,
            'department' => $user->department?->name,
            'context' => $unit?->name ?? $user->division?->name,
            'office' => $unit?->type === 'office' ? $unit->name : null,
            'shorthand_code' => $displayAlias?->alias,
            'staff_id' => $user->employee_number,
            'status' => 'Available',
            'initials' => $user->initials(),
            'score' => $score,
        ];
    }

    private function aliasMatches(RecipientAlias $alias, User $user): bool
    {
        $unit = $user->currentPositionAssignment?->position?->organizationalUnit;

        return match ($alias->target_type) {
            User::class => (int) $alias->target_id === $user->id,
            Position::class => (int) $alias->target_id === $user->currentPositionAssignment?->position_id,
            Department::class => (int) $alias->target_id === $user->department_id,
            Division::class => (int) $alias->target_id === ($unit?->division_id ?? $user->division_id),
            OrganizationalUnit::class => (int) $alias->target_id === $unit?->id,
            default => false,
        };
    }

    /** @param array<string, string> $fields */
    private function directMatchType(array $fields, string $normalized, ?string $unitType): string
    {
        if (str_contains($fields['name'], $normalized) || str_contains($fields['username'], $normalized) || str_contains($fields['staff'], $normalized)) {
            return 'officer';
        }
        if (str_contains($fields['title'], $normalized)) {
            return 'position';
        }
        if (str_contains($fields['department'], $normalized) || str_contains($fields['department_code'], $normalized)) {
            return 'department';
        }
        if (str_contains($fields['division'], $normalized)) {
            return 'directorate';
        }

        return $unitType === 'office' ? 'office' : 'unit';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
