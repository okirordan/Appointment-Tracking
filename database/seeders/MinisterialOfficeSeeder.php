<?php

namespace Database\Seeders;

use App\Models\OrganizationalUnit;
use App\Support\OfficialOrganizationalCodes;
use Illuminate\Database\Seeder;

class MinisterialOfficeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (OfficialOrganizationalCodes::MINISTERIAL_OFFICES as $code => $name) {
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
