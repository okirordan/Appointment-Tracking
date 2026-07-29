<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Division;
use Illuminate\Database\Seeder;

class DivisionSeeder extends Seeder
{
    public function run(): void
    {
        Department::query()->each(function (Department $department): void {
            Division::firstOrCreate(
                ['department_id' => $department->id, 'code' => $department->code.'-OPS'],
                ['name' => 'Operations', 'active' => true],
            );
        });
    }
}
