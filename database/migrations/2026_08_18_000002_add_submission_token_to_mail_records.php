<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->uuid('submission_token')->nullable()->after('register_number')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropUnique(['submission_token']);
            $table->dropColumn('submission_token');
        });
    }
};
