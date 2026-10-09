<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->string('destination_office_snapshot')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->dropColumn('destination_office_snapshot');
        });
    }
};
