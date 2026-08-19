<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correspondence_forwards', function (Blueprint $table) {
            $table->foreignId('origin_annotation_title_id')
                ->nullable()
                ->after('from_organizational_unit_id')
                ->constrained('annotation_titles')
                ->nullOnDelete();
            $table->foreignId('recipient_annotation_title_id')
                ->nullable()
                ->after('origin_annotation_title_id')
                ->constrained('annotation_titles')
                ->nullOnDelete();
            $table->string('origin_title_snapshot')->nullable()->after('recipient_annotation_title_id');
            $table->string('recipient_title_snapshot')->nullable()->after('origin_title_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('correspondence_forwards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_annotation_title_id');
            $table->dropConstrainedForeignId('origin_annotation_title_id');
            $table->dropColumn(['origin_title_snapshot', 'recipient_title_snapshot']);
        });
    }
};
