<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ApprovedMinistryStructureSeeder extends Seeder
{
    /** @var array<string, string> */
    private array $departmentCodes = [
        'Department of Pre-Primary and Primary Education' => 'PPPE',
        'Department of Secondary Education' => 'SE',
        'Department of Higher Education' => 'HE',
        'Department of Education Technical Support Services' => 'ETSS',
        'Department of Libraries, E-Learning and Information Technology' => 'LEIT',
        'Department of Standards and Procedures' => 'SP',
        'Department of Education Inspection and Compliance' => 'IC',
        'Department of TVET Operations and Management' => 'TVET',
        'Department of Higher Education Students Financing' => 'HESF',
        'Department of Health Education and Training' => 'HET',
        'Department of Physical Education and Sports' => 'PES',
        'Department of Finance and Administration' => 'FA',
        'Department of Human Resource Management' => 'HRM',
        'Department of Education Planning and Budgeting' => 'EPB',
        'Department of Education Policy Analysis and Research' => 'EPAR',
    ];

    public function run(): void
    {
        $source = database_path('seeders/data/moes_approved_positions.md');
        if (! is_file($source)) {
            throw new RuntimeException("Approved structure source is missing: {$source}");
        }

        $roles = Role::query()->whereIn('name', ['commissioner', 'secretary', 'officer'])->get()->keyBy('name');
        if ($roles->count() !== 3) {
            throw new RuntimeException('Run RoleSeeder before ApprovedMinistryStructureSeeder.');
        }

        DB::transaction(function () use ($source, $roles): void {
            $activeDivisionIds = [];
            $activeUnitIds = [];
            $activePositionIds = [];
            $departmentIds = [];
            $departmentHeads = [];
            $unitHeads = [];
            $department = null;
            $departmentCode = null;
            $unit = null;

            foreach (file($source, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);

                if (preg_match('/^##\s+Source Note$/u', $line)) {
                    break;
                }

                if (preg_match('/^##\s+\d+\.\s+(.+)$/u', $line, $match)) {
                    $sourceName = trim($match[1]);
                    $departmentCode = $this->departmentCodes[$sourceName] ?? null;
                    if ($departmentCode === null) {
                        throw new RuntimeException("No department mapping exists for {$sourceName}.");
                    }

                    $department = Department::query()->where('code', $departmentCode)->firstOrFail();
                    $departmentIds[] = $department->id;
                    $unit = $this->departmentUnit($department);
                    $activeUnitIds[] = $unit->id;

                    continue;
                }

                if (preg_match('/^###\s+(.+)$/u', $line, $match)) {
                    if ($department === null || $departmentCode === null) {
                        throw new RuntimeException('The approved structure contains a unit before its department.');
                    }

                    [$unit, $divisionId] = $this->subUnit($department, $departmentCode, trim($match[1]));
                    $activeUnitIds[] = $unit->id;
                    if ($divisionId !== null) {
                        $activeDivisionIds[] = $divisionId;
                    }

                    continue;
                }

                if (! preg_match('/^-\s+(.+)$/u', $line, $match)) {
                    continue;
                }

                if ($department === null || $departmentCode === null || $unit === null) {
                    throw new RuntimeException('The approved structure contains a position before its organizational unit.');
                }

                $title = trim($match[1]);
                $supervisorId = $unitHeads[$unit->id] ?? $departmentHeads[$departmentCode] ?? null;
                $position = Position::withTrashed()
                    ->where('organizational_unit_id', $unit->id)
                    ->where('title', $title)
                    ->first() ?? new Position;

                if ($position->exists && $position->trashed()) {
                    $position->restore();
                }

                $position->fill([
                    'organizational_unit_id' => $unit->id,
                    'role_id' => $roles[$this->roleName($title)]->id,
                    'supervisor_position_id' => $supervisorId,
                    'title' => $title,
                    'hierarchy_level' => $this->hierarchyLevel($title),
                    'workflow_capabilities' => $this->capabilities($title),
                    'active' => true,
                ])->save();

                $activePositionIds[] = $position->id;
                $departmentHeads[$departmentCode] ??= $position->id;
                $unitHeads[$unit->id] ??= $position->id;
            }

            Position::query()
                ->whereHas('organizationalUnit', fn ($query) => $query->whereIn('department_id', $departmentIds))
                ->whereNotIn('id', $activePositionIds)
                ->delete();
            OrganizationalUnit::query()
                ->whereIn('department_id', $departmentIds)
                ->whereNotIn('id', $activeUnitIds)
                ->delete();
            Division::query()
                ->whereIn('department_id', $departmentIds)
                ->whereNotIn('id', $activeDivisionIds)
                ->delete();
        });
    }

    private function departmentUnit(Department $department): OrganizationalUnit
    {
        $unit = OrganizationalUnit::withTrashed()->where('code', "ORG-{$department->code}")->first() ?? new OrganizationalUnit;
        if ($unit->exists && $unit->trashed()) {
            $unit->restore();
        }
        $unit->fill([
            'parent_id' => null,
            'department_id' => $department->id,
            'division_id' => null,
            'type' => 'department',
            'name' => $department->name,
            'code' => "ORG-{$department->code}",
            'active' => true,
        ])->save();

        return $unit;
    }

    /** @return array{OrganizationalUnit, int|null} */
    private function subUnit(Department $department, string $departmentCode, string $name): array
    {
        $root = $this->departmentUnit($department);
        $isDivision = Str::contains(Str::lower($name), 'division');
        $type = $isDivision ? 'division' : (Str::contains(Str::lower($name), 'section') ? 'section' : 'unit');
        $suffix = $this->codeSuffix($name);
        $division = null;

        if ($isDivision) {
            $divisionCode = Str::limit("{$departmentCode}-{$suffix}", 30, '');
            $division = Division::withTrashed()
                ->where('department_id', $department->id)
                ->where('code', $divisionCode)
                ->first() ?? new Division;
            if ($division->exists && $division->trashed()) {
                $division->restore();
            }
            $division->fill([
                'department_id' => $department->id,
                'name' => $name,
                'code' => $divisionCode,
                'active' => true,
            ])->save();
        }

        $unitCode = Str::limit("ORG-{$departmentCode}-{$suffix}", 40, '');
        $unit = OrganizationalUnit::withTrashed()->where('code', $unitCode)->first() ?? new OrganizationalUnit;
        if ($unit->exists && $unit->trashed()) {
            $unit->restore();
        }
        $unit->fill([
            'parent_id' => $root->id,
            'department_id' => $department->id,
            'division_id' => $division?->id,
            'type' => $type,
            'name' => $name,
            'code' => $unitCode,
            'active' => true,
        ])->save();

        return [$unit, $division?->id];
    }

    private function codeSuffix(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', Str::ascii($name)) ?: [];
        $initials = collect($words)
            ->reject(fn (string $word) => $word === '' || in_array(Str::lower($word), ['a', 'and', 'of', 'the', 'division', 'office', 'department', 'section', 'unit'], true))
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');

        return ($initials === '' ? 'UNIT' : $initials).'-'.Str::upper(Str::substr(sha1($name), 0, 4));
    }

    private function roleName(string $title): string
    {
        if (preg_match('/Permanent Secretary/iu', $title)) {
            return 'ps';
        }
        if (preg_match('/^(Director|Commissioner(?:\/Secretary)?|Assistant Commissioner|Under[\s-]?Secretary|Principal Assistant Secretary)/iu', $title)) {
            return 'commissioner';
        }
        if (preg_match('/(Personal Secretary|Stenographer Secretary|Office Typist|Pool Stenographer Secretary)/iu', $title)) {
            return 'secretary';
        }

        return 'officer';
    }

    private function hierarchyLevel(string $title): int
    {
        return match (true) {
            (bool) preg_match('/^(Director|Commissioner(?:\/Secretary)?|Under[\s-]?Secretary)/iu', $title) => 20,
            Str::startsWith($title, ['Assistant Commissioner', 'Principal Assistant Secretary']) => 30,
            Str::startsWith($title, ['Principal ', 'Principal']) => 40,
            Str::startsWith($title, ['Senior ']) => 50,
            Str::contains(Str::lower($title), ['assistant', 'attendant', 'driver', 'typist', 'operator', 'receptionist', 'askari', 'draughtsman']) => 80,
            default => 60,
        };
    }

    /** @return list<string> */
    private function capabilities(string $title): array
    {
        if (preg_match('/^(Director|Commissioner(?:\/Secretary)?|Under[\s-]?Secretary)/iu', $title)) {
            return ['assign', 'review', 'approve', 'reject', 'return', 'escalate'];
        }
        if (Str::startsWith($title, ['Assistant Commissioner', 'Principal Assistant Secretary', 'Principal '])) {
            return ['assign', 'review', 'return'];
        }

        return [];
    }
}
