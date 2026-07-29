<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->string('recipient_name', 500)->change();
            $table->string('subject', 700)->change();
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->string('recipient_name')->change();
            $table->string('subject', 500)->change();
        });
    }
};
