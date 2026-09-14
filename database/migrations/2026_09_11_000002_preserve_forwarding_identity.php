<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correspondence_forwards', function (Blueprint $table) {
            $table->string('from_office_snapshot')->nullable();
            $table->string('forwarded_by_name_snapshot')->nullable();
        });
        Schema::table('correspondence_recipients', function (Blueprint $table) {
            $table->string('office_snapshot')->nullable();
        });
        Schema::table('assignment_workflow_steps', function (Blueprint $table) {
            $table->string('sender_name_snapshot')->nullable();
            $table->string('recipient_name_snapshot')->nullable();
            $table->string('sender_office_snapshot')->nullable();
            $table->string('recipient_office_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assignment_workflow_steps', fn (Blueprint $table) => $table->dropColumn([
            'sender_name_snapshot', 'recipient_name_snapshot', 'sender_office_snapshot', 'recipient_office_snapshot',
        ]));
        Schema::table('correspondence_recipients', fn (Blueprint $table) => $table->dropColumn('office_snapshot'));
        Schema::table('correspondence_forwards', fn (Blueprint $table) => $table->dropColumn(['from_office_snapshot', 'forwarded_by_name_snapshot']));
    }
};
