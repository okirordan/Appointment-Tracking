<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Department of Pre-Primary and Primary Education', 'code' => 'PPPE', 'legacy_code' => 'BSE'],
            ['name' => 'Department of Secondary Education', 'code' => 'SE', 'legacy_code' => 'SED'],
            ['name' => 'Department of Higher Education', 'code' => 'HE', 'legacy_code' => 'HED'],
            ['name' => 'Department of Education Technical Support Services', 'code' => 'ETSS'],
            ['name' => 'Department of Libraries, E-Learning and Information Technology', 'code' => 'LEIT'],
            ['name' => 'Department of Standards and Procedures', 'code' => 'SP'],
            ['name' => 'Department of Inspection and Compliance', 'code' => 'IC'],
            ['name' => 'Department of TVET Operations and Management', 'code' => 'TVET'],
            ['name' => 'Department of Higher Education Students Financing', 'code' => 'HESF'],
            ['name' => 'Department of Health Education and Training', 'code' => 'HET'],
            ['name' => 'Department of Physical Education and Sports', 'code' => 'PES', 'legacy_code' => 'SPT'],
            ['name' => 'Department of Finance and Administration', 'code' => 'FA', 'legacy_code' => 'FIN'],
            ['name' => 'Department of Human Resource Management', 'code' => 'HRM'],
            ['name' => 'Department of Education Planning and Budgeting', 'code' => 'EPB'],
            ['name' => 'Department of Education Policy Analysis and Research', 'code' => 'EPAR'],
        ];

        DB::transaction(function () use ($departments): void {
            foreach ($departments as $department) {
                $record = Department::withTrashed()
                    ->where('code', $department['code'])
                    ->when(
                        isset($department['legacy_code']),
                        fn ($query) => $query->orWhere('code', $department['legacy_code']),
                    )
                    ->first();

                if ($record === null) {
                    Department::create([
                        'name' => $department['name'],
                        'code' => $department['code'],
                        'active' => true,
                    ]);

                    continue;
                }

                if ($record->trashed()) {
                    $record->restore();
                }

                $record->update([
                    'name' => $department['name'],
                    'code' => $department['code'],
                    'active' => true,
                ]);
            }

            Department::query()
                ->whereNotIn('code', collect($departments)->pluck('code'))
                ->delete();
        });
    }
}
