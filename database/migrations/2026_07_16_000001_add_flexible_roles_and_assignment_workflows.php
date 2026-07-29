<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('name');
            $table->text('description')->nullable()->after('guard_name');
            $table->unsignedSmallInteger('hierarchy_level')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->string('group_name', 80)->nullable()->index();
            $table->string('description')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('supervisor_user_id')->nullable()->after('division_id')->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by_user_id')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by_user_id')->nullable()->after('deleted_by_user_id')->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable()->after('restored_by_user_id');
        });

        Schema::create('organizational_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->string('type', 40)->index();
            $table->string('name');
            $table->string('code', 40)->nullable()->unique();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('supervisor_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('hierarchy_level')->index();
            $table->json('workflow_capabilities')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acting_for_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_primary')->default(true)->index();
            $table->boolean('is_acting')->default(false)->index();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['user_id', 'position_id', 'starts_at'], 'user_position_period_unique');
        });

        Schema::create('user_profile_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('field_name', 80)->index();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create('user_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 40)->index();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create('user_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('reason')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('creator_user_id')->nullable()->after('assigned_by_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->after('creator_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('current_assignee_user_id')->nullable()->after('assigned_to_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->after('current_assignee_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('current_reviewer_user_id')->nullable()->after('responsible_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('final_approver_user_id')->nullable()->after('current_reviewer_user_id')->constrained('users')->nullOnDelete();
            $table->string('execution_status', 30)->default('not_started')->index();
            $table->string('review_status', 30)->default('not_submitted')->index();
            $table->string('approval_status', 30)->default('pending')->index();
        });

        Schema::create('assignment_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('parent_step_id')->nullable()->constrained('assignment_workflow_steps')->nullOnDelete();
            $table->unsignedSmallInteger('sequence')->index();
            $table->string('status', 40)->default('active')->index();
            $table->text('instructions')->nullable();
            $table->dateTime('assigned_at');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_decision', 40)->nullable();
            $table->text('reviewer_comments')->nullable();
            $table->boolean('is_skipped')->default(false)->index();
            $table->boolean('is_current')->default(true)->index();
            $table->boolean('is_direct')->default(false)->index();
            $table->timestamps();
            $table->unique(['task_id', 'sequence']);
            $table->index(['task_id', 'is_current']);
        });

        Schema::create('assignment_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('participant_type', 30)->index();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'user_id', 'participant_type'], 'assignment_participant_unique');
        });

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->constrained('assignment_workflow_steps')->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending_review')->index();
            $table->text('note');
            $table->timestamp('submitted_at')->index();
            $table->timestamps();
        });

        Schema::create('assignment_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('assignment_submissions')->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained('assignment_workflow_steps')->nullOnDelete();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 40)->index();
            $table->text('comments');
            $table->dateTime('revised_due_at')->nullable();
            $table->timestamp('reviewed_at')->index();
            $table->timestamps();
        });

        // Compatibility backfill: every existing assignment becomes a one-step
        // workflow and keeps its original creator, holder and history intact.
        DB::table('tasks')->orderBy('id')->each(function (object $task) {
            DB::table('tasks')->where('id', $task->id)->update([
                'creator_user_id' => $task->assigned_by_user_id,
                'owner_user_id' => $task->assigned_by_user_id,
                'current_assignee_user_id' => $task->assigned_to_user_id,
                'responsible_user_id' => $task->assigned_to_user_id,
            ]);

            DB::table('assignment_workflow_steps')->insert([
                'task_id' => $task->id,
                'sender_user_id' => $task->assigned_by_user_id,
                'recipient_user_id' => $task->assigned_to_user_id,
                'sequence' => 1,
                'status' => in_array($task->workflow_status, ['completed', 'archived'], true) ? 'completed' : 'active',
                'instructions' => $task->initial_instruction,
                'assigned_at' => $task->created_at,
                'due_at' => $task->due_date,
                'is_current' => ! in_array($task->workflow_status, ['completed', 'archived'], true),
                'is_direct' => true,
                'created_at' => $task->created_at,
                'updated_at' => $task->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_reviews');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignment_participants');
        Schema::dropIfExists('assignment_workflow_steps');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('final_approver_user_id');
            $table->dropConstrainedForeignId('current_reviewer_user_id');
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropConstrainedForeignId('current_assignee_user_id');
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropConstrainedForeignId('creator_user_id');
            $table->dropColumn(['execution_status', 'review_status', 'approval_status']);
        });

        Schema::dropIfExists('user_delegations');
        Schema::dropIfExists('user_lifecycle_events');
        Schema::dropIfExists('user_profile_changes');
        Schema::dropIfExists('user_positions');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('organizational_units');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_user_id');
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropConstrainedForeignId('restored_by_user_id');
            $table->dropColumn('deletion_reason');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['group_name', 'description']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'description', 'hierarchy_level', 'is_active', 'is_system']);
        });
    }
};
