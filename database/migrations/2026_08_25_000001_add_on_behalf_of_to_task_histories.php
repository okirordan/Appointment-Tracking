<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_histories', function (Blueprint $table) {
            $table->foreignId('on_behalf_of_user_id')->nullable()->after('performed_by_user_id')->constrained('users');
            $table->string('on_behalf_of_name_snapshot')->nullable()->after('performed_by_office_snapshot');
            $table->string('on_behalf_of_title_snapshot')->nullable()->after('on_behalf_of_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('task_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('on_behalf_of_user_id');
            $table->dropColumn(['on_behalf_of_name_snapshot', 'on_behalf_of_title_snapshot']);
        });
    }
};
