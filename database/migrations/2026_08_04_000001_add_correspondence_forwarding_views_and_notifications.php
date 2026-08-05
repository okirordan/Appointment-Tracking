<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('assignment_target_type', 20)->default('individual')->after('assignment_level')->index();
            $table->foreignId('assigned_to_organizational_unit_id')->nullable()->after('department_id')->constrained('organizational_units')->nullOnDelete();
            $table->foreignId('assigned_to_department_id')->nullable()->after('assigned_to_organizational_unit_id')->constrained('departments')->nullOnDelete();
            $table->timestamp('first_viewed_at')->nullable()->after('initial_instruction')->index();
            $table->foreignId('first_viewed_by_user_id')->nullable()->after('first_viewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('last_viewed_at')->nullable()->after('first_viewed_by_user_id');
        });

        Schema::table('mail_records', function (Blueprint $table) {
            $table->foreignId('source_mail_record_id')->nullable()->after('task_id')->constrained('mail_records')->nullOnDelete();
            $table->foreignId('routing_task_id')->nullable()->unique()->after('source_mail_record_id')->constrained('tasks')->nullOnDelete();
        });

        Schema::create('assignment_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status_before', 30);
            $table->timestamp('first_viewed_at');
            $table->timestamp('latest_viewed_at');
            $table->unsignedInteger('view_count')->default(1);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
            $table->index(['user_id', 'first_viewed_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('type')->index();
            $table->foreignId('related_mail_record_id')->nullable()->after('related_task_id')->constrained('mail_records')->nullOnDelete();
            $table->string('action_url', 1000)->nullable()->after('related_mail_record_id');
            $table->string('event_key', 191)->nullable()->after('action_url');
            $table->boolean('sensitive')->default(false)->after('event_key');
            $table->unique(['user_id', 'event_key'], 'notification_user_event_unique');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('browser_enabled')->default(false);
            $table->boolean('new_assignments')->default(true);
            $table->boolean('assignment_views')->default(true);
            $table->boolean('deadline_reminders')->default(true);
            $table->boolean('completion_notifications')->default(true);
            $table->boolean('correspondence_updates')->default(true);
            $table->boolean('annotation_updates')->default(true);
            $table->boolean('office_correspondence')->default(true);
            $table->timestamp('browser_permission_denied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 30)->default('aes128gcm');
            $table->string('device_label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('push_subscription_id')->nullable()->constrained('push_subscriptions')->nullOnDelete();
            $table->string('channel', 20)->index();
            $table->string('status', 30)->index();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'channel', 'push_subscription_id'], 'notification_delivery_unique');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('task_histories', function (Blueprint $table) {
                $table->fullText('note', 'task_histories_note_fulltext');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('task_histories', function (Blueprint $table) {
                $table->dropFullText('task_histories_note_fulltext');
            });
        }

        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_preferences');

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notification_user_event_unique');
            $table->dropConstrainedForeignId('related_mail_record_id');
            $table->dropColumn(['category', 'action_url', 'event_key', 'sensitive']);
        });

        Schema::dropIfExists('assignment_views');

        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('routing_task_id');
            $table->dropConstrainedForeignId('source_mail_record_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('first_viewed_by_user_id');
            $table->dropConstrainedForeignId('assigned_to_department_id');
            $table->dropConstrainedForeignId('assigned_to_organizational_unit_id');
            $table->dropColumn(['assignment_target_type', 'first_viewed_at', 'last_viewed_at']);
        });
    }
};
