<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->index('letter_date', 'mail_records_letter_date_index');
            $table->index('registry_file_number', 'mail_records_registry_file_number_index');
            $table->index('dispatch_reference', 'mail_records_dispatch_reference_index');
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropIndex('mail_records_letter_date_index');
            $table->dropIndex('mail_records_registry_file_number_index');
            $table->dropIndex('mail_records_dispatch_reference_index');
        });
    }
};
