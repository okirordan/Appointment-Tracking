<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotation_titles', function (Blueprint $table) {
            $table->id();
            $table->string('shorthand', 100);
            $table->string('normalized_shorthand', 100)->unique();
            $table->string('full_title', 255);
            $table->string('normalized_full_title', 255)->unique();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['active', 'shorthand'], 'annotation_title_shorthand_lookup');
            $table->index(['active', 'full_title'], 'annotation_title_full_lookup');
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->foreignId('annotation_origin_title_id')->nullable()->after('note')->constrained('annotation_titles')->nullOnDelete();
            $table->foreignId('annotation_recipient_title_id')->nullable()->after('annotation_origin_title_id')->constrained('annotation_titles')->nullOnDelete();
            $table->string('annotation_origin_snapshot')->nullable()->after('annotation_recipient_title_id');
            $table->string('annotation_recipient_snapshot')->nullable()->after('annotation_origin_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('task_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('annotation_recipient_title_id');
            $table->dropConstrainedForeignId('annotation_origin_title_id');
            $table->dropColumn(['annotation_origin_snapshot', 'annotation_recipient_snapshot']);
        });

        Schema::dropIfExists('annotation_titles');
    }
};
