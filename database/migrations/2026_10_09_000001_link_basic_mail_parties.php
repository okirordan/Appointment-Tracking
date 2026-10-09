<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->foreignId('source_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('recipient_department_id')->nullable()->constrained('departments')->nullOnDelete();
        });
        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->foreignId('destination_annotation_title_id')->nullable()->constrained('annotation_titles')->nullOnDelete();
            $table->foreignId('destination_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('destination_department_id')->nullable()->constrained('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('correspondence_updates', function (Blueprint $table) {
            foreach (['destination_annotation_title_id', 'destination_user_id', 'destination_department_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
        });
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_department_id');
            $table->dropConstrainedForeignId('recipient_department_id');
        });
    }
};
