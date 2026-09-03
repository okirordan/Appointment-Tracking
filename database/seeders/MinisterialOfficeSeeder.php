<?php

namespace Database\Seeders;

use App\Models\OrganizationalUnit;
use Illuminate\Database\Seeder;

class MinisterialOfficeSeeder extends Seeder
{
    /** @var array<string, string> */
    private array $offices = [
        'OMES' => 'Office of the Minister of Education and Sports',
        'OSMS' => 'Office of the Minister of State for Sports',
        'OSMPE' => 'Office of the Minister of State for Primary Education',
        'OSMHE' => 'Office of the Minister of State for Higher Education',
    ];

    public function run(): void
    {
        foreach ($this->offices as $code => $name) {
            $office = OrganizationalUnit::withTrashed()->updateOrCreate(
                ['code' => $code],
                [
                    'parent_id' => null,
                    'department_id' => null,
                    'division_id' => null,
                    'type' => 'office',
                    'name' => $name,
                    'active' => true,
                ],
            );

            if ($office->trashed()) {
                $office->restore();
            }
        }
    }
}
