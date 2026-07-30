<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->after('organizational_unit_id')
                ->index()
                ->constrained('departments')
                ->nullOnDelete();
        });

        DB::table('mail_records')
            ->select(['id', 'organizational_unit_id', 'task_id'])
            ->orderBy('id')
            ->chunkById(500, function ($records) {
                $unitIds = $records->pluck('organizational_unit_id')->filter()->unique()->values();
                $taskIds = $records->pluck('task_id')->filter()->unique()->values();
                $unitDepartments = DB::table('organizational_units')
                    ->whereIn('id', $unitIds)
                    ->pluck('department_id', 'id');
                $taskDepartments = DB::table('tasks')
                    ->whereIn('id', $taskIds)
                    ->pluck('department_id', 'id');

                foreach ($records as $record) {
                    $departmentId = $unitDepartments[$record->organizational_unit_id] ?? null;
                    $departmentId ??= $taskDepartments[$record->task_id] ?? null;

                    if ($departmentId !== null) {
                        DB::table('mail_records')
                            ->where('id', $record->id)
                            ->update(['department_id' => $departmentId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
