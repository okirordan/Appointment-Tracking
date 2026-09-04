<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPosition;
use App\Support\TemporaryPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CimStaffSeeder extends Seeder
{
    /** @var array<string, true> */
    private array $reservedUsernames = [];

    public function run(): void
    {
        $source = database_path('seeders/data/cim_staff_seed.json');
        $data = json_decode((string) file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);
        $counters = ['created' => 0, 'updated' => 0, 'renamed' => 0, 'positioned' => 0, 'unmatched_positions' => 0];

        DB::transaction(function () use ($data, &$counters): void {
            $roles = Role::query()->whereIn('name', collect(RoleEnum::cases())->map->value)->get()->keyBy('name');
            $departments = Department::query()->get()->keyBy('code');
            $seenExternalIds = [];

            foreach ($data['sections'] as $section) {
                $department = $section['departmentCode'] === null ? null : $departments[$section['departmentCode']] ?? null;
                if ($section['departmentCode'] !== null && $department === null) {
                    throw new RuntimeException("Missing approved department {$section['departmentCode']} for {$section['section']}.");
                }

                foreach ($section['staff'] as $staff) {
                    [$row, $employeeNumber, $name, $sourceTitle, $unitIndex, $positionIndex, $replaceUsername] = $staff;
                    $externalId = 'CIM:'.($employeeNumber === null ? "ROW-{$row}" : $employeeNumber);
                    if (isset($seenExternalIds[$externalId])) {
                        throw new RuntimeException("Duplicate staff identifier {$externalId} in CIM source data.");
                    }
                    $seenExternalIds[$externalId] = true;

                    $position = $this->position($data, $department, $unitIndex, $positionIndex);
                    $user = $this->existingUser($replaceUsername, $employeeNumber, $externalId, $name);
                    $wasExisting = $user !== null;
                    $oldName = $user?->full_name;
                    $permissionRole = $this->permissionRole($roles, $position, $sourceTitle, $replaceUsername);
                    $legacyRole = RoleEnum::tryFrom($permissionRole->name) ?? RoleEnum::Officer;
                    $unit = $position?->organizationalUnit;

                    if ($user === null) {
                        // Imported accounts start with a unique temporary
                        // password and must change it at first login.
                        $user = new User([
                            'username' => $this->uniqueUsername($name),
                            'password' => TemporaryPassword::generate(),
                            'force_password_change' => true,
                        ]);
                    } elseif ($user->trashed()) {
                        $user->restore();
                    }

                    $user->fill([
                        'full_name' => $name,
                        'title' => $employeeNumber === '14208'
                            ? 'Senior Personal Secretary to the Permanent Secretary'
                            : ($position?->title ?? $this->displayTitle($sourceTitle)),
                        'employee_number' => $employeeNumber,
                        'external_id' => $externalId,
                        'role' => $legacyRole->value,
                        'department_id' => $department?->id,
                        'division_id' => $unit?->division_id,
                        'active' => true,
                        'locked' => false,
                    ])->save();
                    $user->syncRoles([$permissionRole]);

                    if ($position === null) {
                        $counters['unmatched_positions']++;
                        UserPosition::query()->where('user_id', $user->id)->where('is_primary', true)->where('active', true)
                            ->update(['active' => false, 'ends_at' => now()]);
                    } else {
                        $this->place($user, $position);
                        $counters['positioned']++;
                    }

                    $counters[$wasExisting ? 'updated' : 'created']++;
                    if ($replaceUsername !== null && $oldName !== $name) {
                        $counters['renamed']++;
                    }
                }
            }

            $this->assignDepartmentHeads($departments);

            AuditLog::create([
                'actor_name_snapshot' => 'System',
                'category' => 'user',
                'action' => 'Imported and mapped CIM staff list',
                'target_type' => 'User',
                'metadata_json' => [
                    ...$counters,
                    'source' => 'Draft stafflist - CIM.xlsx',
                    'hierarchy_records_created' => 0,
                ],
                'outcome' => 'success',
                'created_at' => now(),
            ]);
        });

        $this->command?->info(sprintf(
            'CIM staff mapped: %d created, %d updated, %d renamed, %d positioned, %d retained without an approved position.',
            $counters['created'],
            $counters['updated'],
            $counters['renamed'],
            $counters['positioned'],
            $counters['unmatched_positions'],
        ));
    }

    private function position(array $data, ?Department $department, mixed $unitIndex, mixed $positionIndex): ?Position
    {
        if ($department === null || $unitIndex === null || $positionIndex === null) {
            return null;
        }

        $unitName = $data['units'][$unitIndex] ?? null;
        $positionTitle = $data['positions'][$positionIndex] ?? null;
        if ($unitName === null || $positionTitle === null) {
            throw new RuntimeException('Invalid unit or position dictionary reference in CIM seed data.');
        }

        $position = Position::query()
            ->with(['organizationalUnit', 'role'])
            ->where('title', $positionTitle)
            ->whereHas('organizationalUnit', function ($query) use ($department, $unitName): void {
                $query
                    ->where('department_id', $department->id)
                    ->where('active', true)
                    ->when(
                        Str::startsWith($unitName, 'Department of '),
                        fn ($rootQuery) => $rootQuery->where('code', "ORG-{$department->code}"),
                        fn ($subUnitQuery) => $subUnitQuery->where('name', $unitName),
                    );
            })
            ->where('active', true)
            ->first();

        if ($position === null) {
            throw new RuntimeException("Approved position {$positionTitle} was not found in {$unitName}.");
        }

        return $position;
    }

    private function existingUser(?string $replaceUsername, ?string $employeeNumber, string $externalId, string $name): ?User
    {
        $query = User::withTrashed();
        if ($replaceUsername !== null) {
            return $query->where('username', $replaceUsername)->first();
        }
        if ($employeeNumber !== null) {
            return $query->where('employee_number', $employeeNumber)->orWhere('external_id', $externalId)->first();
        }

        return $query->where('external_id', $externalId)->orWhere('full_name', $name)->first();
    }

    private function permissionRole($roles, ?Position $position, string $title, ?string $replaceUsername): Role
    {
        if ($replaceUsername === 'jkaggwa') {
            return $roles[RoleEnum::Ps->value];
        }
        if ($position?->role !== null) {
            return $position->role;
        }

        $normalized = Str::lower($title);
        $role = match (true) {
            Str::contains($normalized, ['permanent secretary', 'permenent secretary']) => RoleEnum::Ps,
            Str::startsWith($normalized, ['director', 'commissioner', 'assistant commissioner', 'under secretary', 'undersecretary', 'principal assistant secretary']) => RoleEnum::Commissioner,
            Str::contains($normalized, ['secretary', 'typist']) => RoleEnum::Secretary,
            default => RoleEnum::Officer,
        };

        return $roles[$role->value];
    }

    private function displayTitle(string $title): string
    {
        return Str::lower(trim($title)) === 'permenent secretary' ? 'Permanent Secretary' : trim($title);
    }

    private function uniqueUsername(string $name): string
    {
        $words = collect(preg_split('/\s+/', Str::lower(Str::ascii($name))) ?: [])
            ->map(fn (string $word) => preg_replace('/[^a-z0-9]/', '', $word))
            ->filter()
            ->values();
        $base = Str::limit(($words->first()[0] ?? 'u').($words->last() ?? 'staff'), 52, '');
        $candidate = $base;
        $suffix = 2;

        while (isset($this->reservedUsernames[Str::lower($candidate)]) || User::withTrashed()->where('username', $candidate)->exists()) {
            $candidate = Str::limit($base, 55 - strlen((string) $suffix), '').$suffix;
            $suffix++;
        }

        $this->reservedUsernames[Str::lower($candidate)] = true;

        return $candidate;
    }

    private function place(User $user, Position $position): void
    {
        $current = UserPosition::query()
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->where('active', true)
            ->first();

        if ($current?->position_id === $position->id) {
            $current->update(['ends_at' => null, 'active' => true]);

            return;
        }

        if ($current !== null) {
            $current->update(['active' => false, 'ends_at' => now()]);
        }

        UserPosition::create([
            'user_id' => $user->id,
            'position_id' => $position->id,
            'is_primary' => true,
            'is_acting' => false,
            'starts_at' => now(),
            'active' => true,
        ]);
    }

    private function assignDepartmentHeads($departments): void
    {
        $heads = [
            'PPPE' => '733814',
            'SE' => '158598',
            'PES' => '871458',
            'FA' => '71435',
            'HRM' => '17005',
            'EPAR' => '69486',
            'TVET' => '14350',
            'HET' => '14921',
            'HESF' => '1839672',
        ];

        foreach ($heads as $code => $employeeNumber) {
            $head = User::query()->where('employee_number', $employeeNumber)->first();
            if ($head === null || ! isset($departments[$code])) {
                continue;
            }
            $departments[$code]->update(['head_user_id' => $head->id, 'head_name' => $head->full_name]);
        }
    }
}
