<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('assigned_by_role_snapshot')
                ->nullable()
                ->after('assigned_by_user_id');
            $table->foreignId('assigned_by_department_id')
                ->nullable()
                ->after('assigned_by_role_snapshot')
                ->constrained('departments')
                ->nullOnDelete();
        });

        DB::table('tasks')
            ->select(['id', 'assigned_by_user_id'])
            ->orderBy('id')
            ->chunkById(500, function ($tasks) {
                $users = DB::table('users')
                    ->whereIn('id', $tasks->pluck('assigned_by_user_id')->filter()->unique())
                    ->get(['id', 'role', 'department_id'])
                    ->keyBy('id');

                foreach ($tasks as $task) {
                    $assigner = $users->get($task->assigned_by_user_id);
                    if ($assigner === null) {
                        continue;
                    }

                    DB::table('tasks')
                        ->where('id', $task->id)
                        ->update([
                            'assigned_by_role_snapshot' => $assigner->role,
                            'assigned_by_department_id' => $assigner->department_id,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by_department_id');
            $table->dropColumn('assigned_by_role_snapshot');
        });
    }
};
