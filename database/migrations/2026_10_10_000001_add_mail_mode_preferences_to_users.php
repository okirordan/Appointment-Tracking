<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mail_default_mode', 10)->nullable();
            $table->timestamp('mail_onboarded_at')->nullable();
            $table->timestamp('mail_basic_tried_at')->nullable();
            $table->boolean('mail_tips_enabled')->default(true);
            $table->timestamp('mail_tips_dismissed_at')->nullable();
            $table->unsignedInteger('mail_login_count')->default(0);
            $table->unsignedInteger('mail_tip_last_login')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'mail_default_mode', 'mail_onboarded_at', 'mail_basic_tried_at',
            'mail_tips_enabled', 'mail_tips_dismissed_at', 'mail_login_count', 'mail_tip_last_login',
        ]));
    }
};
