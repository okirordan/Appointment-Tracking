<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_workflow_steps', function (Blueprint $table) {
            $table->string('recipient_title_snapshot')->nullable();
            $table->string('recipient_role_snapshot')->nullable();
            $table->string('recipient_department_snapshot')->nullable();
            $table->string('recipient_division_snapshot')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
        });
        Schema::table('task_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('annotation_origin_user_id')->nullable();
            $table->json('annotation_recipient_user_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_histories', fn (Blueprint $table) => $table->dropColumn(['annotation_origin_user_id', 'annotation_recipient_user_ids']));
        Schema::table('assignment_workflow_steps', fn (Blueprint $table) => $table->dropColumn([
            'recipient_title_snapshot', 'recipient_role_snapshot', 'recipient_department_snapshot', 'recipient_division_snapshot', 'progress_percent',
        ]));
    }
};
