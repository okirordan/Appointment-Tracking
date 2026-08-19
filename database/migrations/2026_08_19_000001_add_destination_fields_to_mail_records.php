<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->string('destination_type', 20)->nullable()->after('recipient_name')->index();
            $table->foreignId('recipient_annotation_title_id')->nullable()->after('destination_type')
                ->constrained('annotation_titles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_annotation_title_id');
            $table->dropColumn('destination_type');
        });
    }
};
