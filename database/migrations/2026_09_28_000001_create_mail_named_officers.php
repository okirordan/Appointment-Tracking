<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_named_officers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('normalized_name')->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('mail_records', function (Blueprint $table) {
            $table->foreignId('recipient_named_officer_id')->nullable()->after('recipient_staff_user_id')
                ->constrained('mail_named_officers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_named_officer_id');
        });

        Schema::dropIfExists('mail_named_officers');
    }
};
