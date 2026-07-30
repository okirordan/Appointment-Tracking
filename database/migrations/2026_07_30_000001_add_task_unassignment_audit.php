<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_participants', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('participant_type')->index();
            $table->dateTime('assigned_at')->nullable()->after('active');
            $table->dateTime('unassigned_at')->nullable()->after('assigned_at')->index();
        });

        DB::table('assignment_participants')
            ->whereNull('assigned_at')
            ->update(['assigned_at' => DB::raw('created_at')]);

        Schema::create('task_unassignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignment_workflow_step_id')->nullable()->constrained('assignment_workflow_steps')->nullOnDelete();
            $table->foreignId('originally_assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('unassigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task_reference_snapshot');
            $table->string('task_title_snapshot');
            $table->string('assigned_user_name_snapshot');
            $table->string('unassigned_by_name_snapshot');
            $table->dateTime('original_assignment_at');
            $table->dateTime('unassigned_at')->index();
            $table->text('reason');
            $table->text('comments')->nullable();
            $table->string('status_before', 30);
            $table->string('status_after', 30);
            $table->timestamp('created_at');

            $table->index(['task_id', 'user_id', 'unassigned_at'], 'task_unassignment_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_unassignments');

        Schema::table('assignment_participants', function (Blueprint $table) {
            $table->dropColumn(['active', 'assigned_at', 'unassigned_at']);
        });
    }
};
