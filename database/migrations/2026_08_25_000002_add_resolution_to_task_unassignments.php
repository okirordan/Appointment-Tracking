<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_unassignments', function (Blueprint $table) {
            $table->string('resolution', 30)->nullable()->after('comments');
            $table->foreignId('replacement_user_id')->nullable()->after('resolution')->constrained('users')->nullOnDelete();
            $table->string('replacement_user_name_snapshot')->nullable()->after('replacement_user_id');
            $table->text('resolution_note')->nullable()->after('replacement_user_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('task_unassignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replacement_user_id');
            $table->dropColumn(['resolution', 'replacement_user_name_snapshot', 'resolution_note']);
        });
    }
};
