<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->string('source_type', 20)->nullable()->after('sender_organisation')->index();
            $table->foreignId('annotation_title_id')->nullable()->after('source_type')
                ->constrained('annotation_titles')->nullOnDelete();
            $table->string('external_source')->nullable()->after('annotation_title_id');
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('annotation_title_id');
            $table->dropColumn(['source_type', 'external_source']);
        });
    }
};
