<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_mail_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->string('kind', 24)->default('organization');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('mail_records', function (Blueprint $table) {
            $table->foreignId('external_source_id')->nullable()->constrained('external_mail_sources')->nullOnDelete();
        });
        Schema::table('correspondence_updates', function (Blueprint $table) {
            $table->string('source_name_snapshot')->nullable();
            $table->foreignId('external_source_id')->nullable()->constrained('external_mail_sources')->nullOnDelete();
            $table->foreignId('source_annotation_title_id')->nullable()->constrained('annotation_titles')->nullOnDelete();
            $table->foreignId('source_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_department_id')->nullable()->constrained('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('correspondence_updates', function (Blueprint $table) {
            foreach (['external_source_id', 'source_annotation_title_id', 'source_user_id', 'source_department_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn('source_name_snapshot');
        });
        Schema::table('mail_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('external_source_id'));
        Schema::dropIfExists('external_mail_sources');
    }
};
