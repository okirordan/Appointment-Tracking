<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $officeId = DB::table('organizational_units')
            ->where('name', 'Office of the Permanent Secretary')
            ->value('id');

        if ($officeId === null) {
            $officeId = DB::table('organizational_units')->insertGetId([
                'parent_id' => null,
                'department_id' => null,
                'division_id' => null,
                'type' => 'office',
                'name' => 'Office of the Permanent Secretary',
                'code' => 'OPS',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        } else {
            DB::table('organizational_units')->where('id', $officeId)->update([
                'type' => 'office',
                'active' => true,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);
        }

        $gorretiId = DB::table('users')
            ->where('employee_number', '14208')
            ->orWhere('full_name', 'Gorreti Namukwaya')
            ->value('id');

        if ($gorretiId !== null) {
            DB::table('secretary_office_attachments')
                ->where('secretary_user_id', $gorretiId)
                ->where('active', true)
                ->update([
                    'organizational_unit_id' => $officeId,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // The office is part of the approved organization structure and is retained.
    }
};
