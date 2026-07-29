<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secretary_office_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secretary_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->string('official_job_title');
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->boolean('delegated_actions_permitted')->default(false);
            $table->json('delegated_permissions')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['secretary_user_id', 'active', 'starts_at'], 'secretary_attachment_current_idx');
            $table->index(['supervisor_user_id', 'active'], 'supervisor_secretary_current_idx');
        });

        Schema::create('office_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secretary_office_attachment_id')->constrained('secretary_office_attachments')->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_schedule_items');
        Schema::dropIfExists('secretary_office_attachments');
    }
};
