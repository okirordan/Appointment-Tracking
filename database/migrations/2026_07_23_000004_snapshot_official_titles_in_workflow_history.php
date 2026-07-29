<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_histories', function (Blueprint $table) {
            $table->string('performed_by_title_snapshot')->nullable()->after('performed_by_name_snapshot');
        });
        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->string('submitted_by_title_snapshot')->nullable()->after('submitted_by_user_id');
        });
        Schema::table('assignment_reviews', function (Blueprint $table) {
            $table->string('reviewer_title_snapshot')->nullable()->after('reviewer_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_reviews', function (Blueprint $table) {
            $table->dropColumn('reviewer_title_snapshot');
        });
        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->dropColumn('submitted_by_title_snapshot');
        });
        Schema::table('task_histories', function (Blueprint $table) {
            $table->dropColumn('performed_by_title_snapshot');
        });
    }
};
