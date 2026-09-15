<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('severity', 16)->default('info')->index();
            $table->index(['actor_user_id', 'created_at']);
        });
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->foreignId('target_user_id')->constrained('users');
            $table->unsignedInteger('actor_version');
            $table->unsignedInteger('target_version');
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
        });
        DB::table('roles')->insertOrIgnore([
            'name' => 'super_admin', 'guard_name' => 'web', 'display_name' => 'Super Admin',
            'description' => 'Reserved support role. Assigned only by the server administrator.',
            'hierarchy_level' => 0, 'is_active' => true, 'is_system' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve immutable support-session history and the reserved role on rollback.
    }
};
