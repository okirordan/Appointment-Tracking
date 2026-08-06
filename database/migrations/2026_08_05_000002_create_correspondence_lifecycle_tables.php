<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correspondences', function (Blueprint $table) {
            $table->id();
            $table->string('canonical_reference')->nullable()->index();
            $table->string('origin_direction', 20)->default('incoming')->index();
            $table->unsignedBigInteger('originating_mail_record_id')->nullable()->index();
            $table->foreignId('office_supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('confidentiality', 20)->default('normal')->index();
            $table->string('current_status', 40)->default('incoming')->index();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['organizational_unit_id', 'current_status'], 'correspondence_unit_status_idx');
            $table->index(['department_id', 'current_status'], 'correspondence_department_status_idx');
        });

        Schema::table('mail_records', function (Blueprint $table) {
            $table->foreignId('correspondence_id')
                ->nullable()
                ->after('id')
                ->index()
                ->constrained('correspondences')
                ->nullOnDelete();
        });

        Schema::create('correspondence_forwards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('correspondences')->cascadeOnDelete();
            $table->foreignId('forwarded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('on_behalf_of_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->text('instructions')->nullable();
            $table->string('status', 30)->default('sent')->index();
            $table->timestamp('forwarded_at')->index();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('withdrawal_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('correspondence_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('correspondences')->cascadeOnDelete();
            $table->foreignId('correspondence_forward_id')->constrained('correspondence_forwards')->cascadeOnDelete();
            $table->string('recipient_type', 20)->index();
            $table->string('purpose', 30)->default('information')->index();
            $table->string('target_type', 30)->default('individual')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('external_name')->nullable();
            $table->string('external_organisation')->nullable();
            $table->string('recipient_name_snapshot');
            $table->string('recipient_title_snapshot')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('added_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('added_at');
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'active', 'recipient_type'], 'correspondence_recipient_user_idx');
            $table->index(['correspondence_id', 'active', 'purpose'], 'correspondence_recipient_case_idx');
        });

        Schema::create('correspondence_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('correspondences')->cascadeOnDelete();
            $table->foreignId('correspondence_forward_id')->nullable()->constrained('correspondence_forwards')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('type', 30)->index();
            $table->text('body')->nullable();
            $table->string('status_from', 40)->nullable();
            $table->string('status_to', 40)->nullable();
            $table->json('recipient_summary')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('performed_by_name_snapshot');
            $table->string('performed_by_title_snapshot')->nullable();
            $table->string('performed_by_role_snapshot')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['correspondence_id', 'created_at'], 'correspondence_update_timeline_idx');
        });

        Schema::create('correspondence_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('correspondences')->cascadeOnDelete();
            $table->foreignId('correspondence_update_id')->nullable()->constrained('correspondence_updates')->nullOnDelete();
            $table->foreignId('correspondence_forward_id')->nullable()->constrained('correspondence_forwards')->nullOnDelete();
            $table->uuid('version_group')->index();
            $table->unsignedInteger('version_number')->default(1);
            $table->foreignId('supersedes_attachment_id')->nullable()->constrained('correspondence_attachments')->nullOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->string('original_filename');
            $table->string('storage_key');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->index();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            $table->timestamps();

            $table->unique(['version_group', 'version_number'], 'correspondence_attachment_version_unique');
        });

        Schema::create('correspondence_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correspondence_id')->constrained('correspondences')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 20)->default('read');
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at'], 'correspondence_grant_user_idx');
        });

        $this->backfillExistingMail();
    }

    private function backfillExistingMail(): void
    {
        $now = now();

        DB::table('mail_records')
            ->whereNull('source_mail_record_id')
            ->orderBy('id')
            ->chunkById(500, function ($records) use ($now): void {
                foreach ($records as $record) {
                    $status = $this->lifecycleStatus($record);
                    $correspondenceId = DB::table('correspondences')->insertGetId([
                        'canonical_reference' => $record->register_number,
                        'origin_direction' => $record->direction,
                        'originating_mail_record_id' => $record->id,
                        'office_supervisor_user_id' => $record->office_supervisor_user_id,
                        'organizational_unit_id' => $record->organizational_unit_id,
                        'department_id' => $record->department_id,
                        'confidentiality' => $record->confidentiality,
                        'current_status' => $status,
                        'last_activity_at' => $record->updated_at ?? $record->created_at,
                        'closed_at' => in_array($status, ['closed', 'withdrawn'], true) ? ($record->updated_at ?? $now) : null,
                        'withdrawn_at' => $status === 'withdrawn' ? ($record->updated_at ?? $now) : null,
                        'lock_version' => 1,
                        'created_at' => $record->created_at ?? $now,
                        'updated_at' => $record->updated_at ?? $now,
                    ]);

                    DB::table('mail_records')->where('id', $record->id)->update(['correspondence_id' => $correspondenceId]);
                }
            });

        DB::table('mail_records')
            ->whereNotNull('source_mail_record_id')
            ->orderBy('id')
            ->chunkById(500, function ($records) use ($now): void {
                foreach ($records as $record) {
                    $source = DB::table('mail_records')->where('id', $record->source_mail_record_id)->first();
                    $correspondenceId = $source?->correspondence_id;
                    if ($correspondenceId === null) {
                        continue;
                    }

                    DB::table('mail_records')->where('id', $record->id)->update(['correspondence_id' => $correspondenceId]);
                    DB::table('correspondences')->where('id', $correspondenceId)->update([
                        'current_status' => $record->routing_task_id === null ? 'forwarded' : 'action_required',
                        'last_activity_at' => $record->updated_at ?? $record->created_at,
                        'updated_at' => $record->updated_at ?? $now,
                    ]);

                    $forwardId = DB::table('correspondence_forwards')->insertGetId([
                        'correspondence_id' => $correspondenceId,
                        'forwarded_by_user_id' => $record->captured_by_user_id,
                        'on_behalf_of_user_id' => $record->prepared_on_behalf_of_user_id,
                        'from_organizational_unit_id' => $record->organizational_unit_id,
                        'instructions' => $record->details,
                        'status' => 'sent',
                        'forwarded_at' => $record->dispatched_at ?? $record->created_at ?? $now,
                        'created_at' => $record->created_at ?? $now,
                        'updated_at' => $record->updated_at ?? $now,
                    ]);

                    $task = $record->routing_task_id === null
                        ? null
                        : DB::table('tasks')->where('id', $record->routing_task_id)->first();
                    DB::table('correspondence_recipients')->insert([
                        'correspondence_id' => $correspondenceId,
                        'correspondence_forward_id' => $forwardId,
                        'recipient_type' => 'to',
                        'purpose' => $task === null ? 'information' : 'action_required',
                        'target_type' => $task?->assignment_target_type ?? 'external',
                        'user_id' => $task?->assigned_to_user_id,
                        'organizational_unit_id' => $task?->assigned_to_organizational_unit_id,
                        'department_id' => $task?->assigned_to_department_id,
                        'task_id' => $task?->id,
                        'external_name' => $task === null ? $record->recipient_name : null,
                        'external_organisation' => null,
                        'recipient_name_snapshot' => $record->recipient_name,
                        'recipient_title_snapshot' => null,
                        'due_date' => $task?->due_date,
                        'active' => true,
                        'added_by_user_id' => $record->captured_by_user_id,
                        'added_at' => $record->created_at ?? $now,
                        'created_at' => $record->created_at ?? $now,
                        'updated_at' => $record->updated_at ?? $now,
                    ]);
                }
            });
    }

    private function lifecycleStatus(object $record): string
    {
        if (in_array($record->status, ['completed', 'archived', 'delivered'], true)) {
            return 'closed';
        }
        if ($record->status === 'awaiting_review') {
            return 'under_review';
        }
        if (in_array($record->status, ['assigned', 'forwarded'], true) || $record->task_id !== null) {
            return $record->task_id === null ? 'forwarded' : 'action_required';
        }

        return $record->direction === 'incoming' ? 'incoming' : 'forwarded';
    }

    public function down(): void
    {
        Schema::dropIfExists('correspondence_access_grants');
        Schema::dropIfExists('correspondence_attachments');
        Schema::dropIfExists('correspondence_updates');
        Schema::dropIfExists('correspondence_recipients');
        Schema::dropIfExists('correspondence_forwards');
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('correspondence_id');
        });
        Schema::dropIfExists('correspondences');
    }
};
