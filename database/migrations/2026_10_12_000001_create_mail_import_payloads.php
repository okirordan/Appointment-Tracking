<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_import_payloads', function (Blueprint $table) {
            $table->foreignId('mail_record_id')->primary()->constrained('mail_records')->cascadeOnDelete();
            $table->longText('raw_details');
            $table->text('original_sender_name')->nullable();
            $table->text('original_recipient_name')->nullable();
            $table->timestamp('repaired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_import_payloads');
    }
};
