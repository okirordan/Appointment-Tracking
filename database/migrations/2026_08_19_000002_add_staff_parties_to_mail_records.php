<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->foreignId('source_staff_user_id')->nullable()->after('annotation_title_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_staff_user_id')->nullable()->after('recipient_annotation_title_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_staff_user_id');
            $table->dropConstrainedForeignId('source_staff_user_id');
        });
    }
};
