<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_histories', function (Blueprint $table) {
            $table->string('performed_by_office_snapshot')->nullable()->after('performed_by_title_snapshot');
        });

        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->string('performed_by_office_snapshot')->nullable()->after('performed_by_title_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->dropColumn('performed_by_office_snapshot');
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->dropColumn('performed_by_office_snapshot');
        });
    }
};
