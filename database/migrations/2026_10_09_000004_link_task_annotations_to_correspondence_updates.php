<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->foreignId('task_history_id')->nullable()->constrained('task_histories');
            $table->unique(['correspondence_id', 'task_history_id'], 'correspondence_task_history_unique');
        });
    }

    public function down(): void
    {
        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->dropUnique('correspondence_task_history_unique');
            $table->dropConstrainedForeignId('task_history_id');
        });
    }
};
