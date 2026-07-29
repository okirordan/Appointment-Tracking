<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignee_registry', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('title')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('assignment_level', 20)->index();
            $table->foreignId('assigned_by_user_id')->constrained('users');
            $table->foreignId('assigned_to_user_id')->nullable()->index()->constrained('users');
            $table->foreignId('assignee_registry_id')->nullable()->constrained('assignee_registry');
            $table->string('assigned_to_name_snapshot');
            $table->foreignId('department_id')->nullable()->index()->constrained('departments');
            $table->string('priority', 20)->index();
            $table->date('due_date')->nullable()->index();
            $table->date('original_due_date')->nullable();
            $table->string('workflow_status', 30)->index();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->text('initial_instruction')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index('title');
        });

        Schema::create('task_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->index()->constrained('tasks');
            $table->string('action_type', 40);
            $table->text('note')->nullable();
            $table->string('status', 30)->nullable();
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users');
            $table->string('performed_by_name_snapshot');
            $table->string('performed_by_role', 20);
            $table->timestamp('created_at');
        });

        Schema::create('evidence_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->index()->constrained('tasks');
            $table->foreignId('history_id')->nullable()->constrained('task_histories');
            $table->string('original_filename');
            $table->string('storage_key');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->timestamp('uploaded_at');
            $table->softDeletes();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained('users');
            $table->string('type', 30);
            $table->string('message', 500);
            $table->text('detail')->nullable();
            $table->foreignId('related_task_id')->nullable()->constrained('tasks');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'is_read']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable();
            $table->string('actor_name_snapshot');
            $table->string('category', 20)->index();
            $table->string('action', 255);
            $table->string('target_type', 40)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('metadata_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('outcome', 10)->default('success');
            $table->timestamp('created_at')->index();
        });

        Schema::create('search_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained('users');
            $table->string('query');
            $table->json('filter_json')->nullable();
            $table->timestamp('searched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_histories');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('evidence_attachments');
        Schema::dropIfExists('task_histories');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('assignee_registry');
    }
};
