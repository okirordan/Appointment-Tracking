<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_records', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 20)->index();
            $table->string('register_number')->unique();
            $table->string('sender_name');
            $table->string('sender_organisation')->nullable();
            $table->string('recipient_name');
            $table->string('subject');
            $table->text('details')->nullable();
            $table->string('correspondence_reference')->nullable()->index();
            $table->date('letter_date')->nullable();
            $table->date('received_date')->nullable()->index();
            $table->date('sent_date')->nullable()->index();
            $table->string('receipt_method', 30)->nullable();
            $table->string('confidentiality', 20)->default('normal')->index();
            $table->string('registry_file_number')->nullable();
            $table->foreignId('captured_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->unique()->constrained('tasks')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['direction', 'created_at']);
            $table->index(['direction', 'sender_name']);
            $table->index(['direction', 'subject']);
        });

        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_record_id')->constrained('mail_records')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('storage_key');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['mail_record_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_attachments');
        Schema::dropIfExists('mail_records');
    }
};
