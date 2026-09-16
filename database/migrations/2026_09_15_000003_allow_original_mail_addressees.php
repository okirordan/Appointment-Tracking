<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Original addressees exist before a letter is forwarded.
        Schema::table('correspondence_recipients', function (Blueprint $table) {
            $table->unsignedBigInteger('correspondence_forward_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('correspondence_recipients')->whereNull('correspondence_forward_id')->exists()) {
            throw new RuntimeException('Original addressees must be preserved; this migration cannot be rolled back while they exist.');
        }
        Schema::table('correspondence_recipients', function (Blueprint $table) {
            $table->unsignedBigInteger('correspondence_forward_id')->nullable(false)->change();
        });
    }
};
