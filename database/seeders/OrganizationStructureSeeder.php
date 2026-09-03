<?php

namespace Database\Seeders;

use App\Enums\OrganizationalUnitType;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganizationStructureSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->structure() as $index => $node) {
                $this->seedNode($node, null, $index);
            }
        });
    }

    /** @param array<string, mixed> $node */
    private function seedNode(array $node, ?OrganizationalUnit $parent, int $sortOrder): OrganizationalUnit
    {
        $department = $node['type'] === OrganizationalUnitType::Department->value
            ? $this->department($node['name'], $node['legacy_code'] ?? $node['code'])
            : null;
        $departmentId = $department?->id ?? $parent?->department_id;
        $division = $node['type'] === OrganizationalUnitType::Division->value && $departmentId !== null
            ? $this->division($departmentId, $node['name'], $node['legacy_code'] ?? $node['code'])
            : null;
        $divisionId = $division?->id ?? $parent?->division_id;

        $entity = OrganizationalUnit::withTrashed()->where('code', $node['code'])->first();
        if ($entity === null && $department !== null) {
            $entity = OrganizationalUnit::withTrashed()
                ->where('department_id', $department->id)
                ->whereNull('division_id')
                ->where('type', 'department')
                ->first();
        }
        if ($entity === null && $division !== null) {
            $entity = OrganizationalUnit::withTrashed()->where('division_id', $division->id)->first();
        }
        $entity ??= new OrganizationalUnit;
        if ($entity->exists && $entity->trashed()) {
            $entity->restore();
        }
        $entity->fill([
            'parent_id' => $parent?->id,
            'department_id' => $departmentId,
            'division_id' => $divisionId,
            'type' => $node['type'],
            'name' => $node['name'],
            'code' => $node['code'],
            'description' => $node['description'] ?? null,
            'is_top_level' => $parent === null,
            'sort_order' => $sortOrder,
            'active' => true,
        ])->save();

        $department?->update(['organizational_unit_id' => $entity->id]);
        $division?->update(['organizational_unit_id' => $entity->id]);

        foreach ($node['children'] ?? [] as $childIndex => $child) {
            $this->seedNode($child, $entity, $childIndex);
        }

        return $entity;
    }

    private function department(string $name, string $code): Department
    {
        $department = Department::withTrashed()
            ->where('code', $code)
            ->orWhere('name', $name)
            ->first() ?? new Department;
        if ($department->exists && $department->trashed()) {
            $department->restore();
        }
        $department->fill(['name' => $name, 'code' => $code, 'active' => true])->save();

        return $department;
    }

    private function division(int $departmentId, string $name, string $code): Division
    {
        $division = Division::withTrashed()
            ->where('department_id', $departmentId)
            ->where(fn ($query) => $query->where('code', $code)->orWhere('name', $name))
            ->first() ?? new Division;
        if ($division->exists && $division->trashed()) {
            $division->restore();
        }
        $division->fill([
            'department_id' => $departmentId,
            'name' => $name,
            'code' => $code,
            'active' => true,
        ])->save();

        return $division;
    }

    /** @return list<array<string, mixed>> */
    private function structure(): array
    {
        return [
            [
                'code' => 'MOES',
                'name' => 'Ministry of Education and Sports',
                'type' => OrganizationalUnitType::Ministry->value,
                'description' => 'Authoritative organizational structure for the Ministry of Education and Sports.',
                'children' => [
                    $this->office('OMES', 'Office of the Minister of Education and Sports'),
                    $this->office('OSMPE', 'Office of the Minister of State for Primary Education'),
                    $this->office('OSMHE', 'Office of the Minister of State for Higher Education'),
                    $this->office('OSMS', 'Office of the Minister of State for Sports'),
                    [
                        ...$this->office('OPS', 'Office of the Permanent Secretary'),
                        'children' => [
                            $this->departmentNode('FA', 'Department of Finance and Administration', [
                                $this->divisionNode('FA-FIN', 'Division of Finance and Accounts'),
                                $this->divisionNode('FA-CM', 'Division of Construction Management'),
                                $this->entity('FA-GAS', 'General Administration Section', OrganizationalUnitType::Section),
                                $this->entity('FA-IMU', 'Inventory Management Unit', OrganizationalUnitType::Unit),
                                $this->entity('FA-RMS', 'Records Management Section', OrganizationalUnitType::Section),
                                $this->entity('FA-COMMS', 'Communications Section', OrganizationalUnitType::Section),
                                $this->entity('FA-RC', 'Resource Centre', OrganizationalUnitType::Unit),
                            ]),
                            $this->departmentNode('HRM', 'Department of Human Resource Management'),
                            $this->departmentNode('EPB', 'Department of Education Planning and Budgeting'),
                            $this->departmentNode('EPAR', 'Department of Education Policy Analysis and Research', [
                                $this->divisionNode('EPAR-EPA', 'Division of Education Policy Analysis'),
                                $this->divisionNode('EPAR-RI', 'Division of Research and Innovation'),
                            ]),
                            $this->departmentNode('TVET', 'Department of TVET Operations and Management', [
                                $this->entity('TVET-TSPA', 'TVET Training Standards, Procedures and Admissions', OrganizationalUnitType::FunctionalArea),
                                $this->entity('TVET-TCIS', 'TVET Trainer and Curriculum Implementation Support', OrganizationalUnitType::FunctionalArea),
                                $this->entity('TVET-IDMS', "TVET Institutions' Development and Management Support", OrganizationalUnitType::FunctionalArea),
                            ]),
                            $this->departmentNode('HESF', 'Department of Higher Education Students Financing', [
                                $this->entity('HESF-LAA', 'Loans Assessment and Award', OrganizationalUnitType::Section),
                                $this->entity('HESF-LR', 'Loans Recovery', OrganizationalUnitType::Section),
                                $this->entity('HESF-DMU', 'Database Management Unit', OrganizationalUnitType::Unit),
                            ]),
                            $this->departmentNode('HET', 'Department of Health Education and Training', [
                                $this->entity('HET-TSPA', 'Training Standards, Procedures and Admissions', OrganizationalUnitType::Section),
                                $this->entity('HET-TCIS', 'Trainer and Curriculum Implementation Support', OrganizationalUnitType::Section),
                                $this->entity('HET-IDMS', "Institutions' Development and Management Support", OrganizationalUnitType::Section),
                            ]),
                            $this->departmentNode('PES', 'Department of Physical Education and Sports', [
                                $this->entity('PES-SP', 'PES Standards and Procedures', OrganizationalUnitType::Section),
                                $this->entity('PES-TCIS', 'PES Trainer and Curriculum Implementation Support', OrganizationalUnitType::Section),
                                $this->entity('PES-IDCS', 'PES Institutions Development and Competitions Support', OrganizationalUnitType::Section),
                            ]),
                            $this->entity('M-E', 'Division of Monitoring and Evaluation', OrganizationalUnitType::Division),
                            $this->entity('IA', 'Division of Internal Audit', OrganizationalUnitType::Division),
                            $this->entity('PDU', 'Procurement and Disposal Unit', OrganizationalUnitType::Unit),
                            [
                                ...$this->office('UNATCOM', 'Desk for UNATCOM'),
                                'children' => [
                                    $this->entity('UNATCOM-CI', 'Communication and Information Unit', OrganizationalUnitType::Unit),
                                    $this->entity('UNATCOM-C', 'Culture Unit', OrganizationalUnitType::Unit),
                                    $this->entity('UNATCOM-NS', 'Natural Sciences Unit', OrganizationalUnitType::Unit),
                                    $this->entity('UNATCOM-SHS', 'Social and Human Sciences Unit', OrganizationalUnitType::Unit),
                                    $this->entity('UNATCOM-E', 'Education Unit', OrganizationalUnitType::Unit),
                                ],
                            ],
                        ],
                    ],
                    $this->functionalArea('EAT', 'Education Administration and Training', [
                        $this->departmentNode('PPPE', 'Department of Pre-Primary and Primary Education', [
                            $this->divisionNode('PPPE-SRL', 'Division of School Registration and Licensing'),
                            $this->divisionNode('PPPE-TCIS', 'Division of Teacher and Curriculum Implementation Support'),
                            $this->divisionNode('PPPE-SDMS', 'Division of School Development and Management Support'),
                        ]),
                        $this->departmentNode('SE', 'Department of Secondary Education', [
                            $this->divisionNode('SE-SRL', 'Division of School Registration and Licensing'),
                            $this->divisionNode('SE-TCIS', 'Division of Teacher and Curriculum Implementation Support'),
                            $this->divisionNode('SE-SDMS', 'Division of School Development and Management Support'),
                        ]),
                        $this->departmentNode('HE', 'Department of Higher Education', [
                            $this->divisionNode('HE-UE', 'Division of University Education and Other Degree Awarding Institutions'),
                            $this->divisionNode('HE-TETD', 'Division of Teacher Education, Training and Development'),
                            $this->divisionNode('HE-ASSA', 'Division of Admissions, Scholarships and Students Affairs'),
                        ]),
                        $this->departmentNode('ETSS', 'Department of Education Technical Support Services', [
                            $this->divisionNode('ETSS-SNE', 'Division of Special Needs Education'),
                            $this->divisionNode('ETSS-GC', 'Division of Guidance and Counselling'),
                            $this->divisionNode('ETSS-IM', 'Division of Instructional Materials'),
                            $this->divisionNode('ETSS-SHLS', 'Division of School Health and Life Skills'),
                        ]),
                        $this->departmentNode('LEIT', 'Department of Library, E-Learning and Information Technology', [
                            $this->divisionNode('LEIT-EL', 'Division of E-Learning'),
                            $this->divisionNode('LEIT-EITS', 'Division of Educational Information Technology Services'),
                            $this->divisionNode('LEIT-LDS', 'Division of Library and Documentation Services'),
                        ]),
                    ]),
                    $this->functionalArea('ESQA', 'Education Standards and Quality Assurance', [
                        $this->departmentNode('SP', 'Department of Education Standards and Procedures'),
                        $this->departmentNode('IC', 'Department of Education Inspection and Compliance'),
                    ]),
                ],
            ],
            [
                'code' => 'EXTERNAL',
                'name' => 'Affiliated / External Bodies',
                'type' => OrganizationalUnitType::AffiliatedBody->value,
                'description' => 'Bodies associated with the education sector that do not inherit internal Ministry permissions.',
                'children' => collect([
                    'Uganda National Examinations Board',
                    'National Council for Higher Education',
                    'National Curriculum Development Centre',
                    'National Council of Sports',
                    'Uganda Vocational and Technical Assessment Board',
                    'Uganda Health Professions Assessment Board',
                    'Universities and other Tertiary Institutions',
                    'Education Service Commission',
                    'TVET Council',
                    'Teacher Council',
                ])->map(fn (string $name, int $index) => [
                    'code' => 'EXT-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'type' => OrganizationalUnitType::AffiliatedBody->value,
                ])->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function office(string $code, string $name): array
    {
        return $this->entity($code, $name, OrganizationalUnitType::Office);
    }

    /** @param list<array<string, mixed>> $children @return array<string, mixed> */
    private function functionalArea(string $code, string $name, array $children): array
    {
        return [...$this->entity($code, $name, OrganizationalUnitType::FunctionalArea), 'children' => $children];
    }

    /** @param list<array<string, mixed>> $children @return array<string, mixed> */
    private function departmentNode(string $code, string $name, array $children = []): array
    {
        return [...$this->entity('ORG-'.$code, $name, OrganizationalUnitType::Department), 'legacy_code' => $code, 'children' => $children];
    }

    /** @return array<string, mixed> */
    private function divisionNode(string $code, string $name): array
    {
        return [...$this->entity('ORG-'.$code, $name, OrganizationalUnitType::Division), 'legacy_code' => $code];
    }

    /** @return array<string, mixed> */
    private function entity(string $code, string $name, OrganizationalUnitType $type): array
    {
        return ['code' => $code, 'name' => $name, 'type' => $type->value];
    }
}
