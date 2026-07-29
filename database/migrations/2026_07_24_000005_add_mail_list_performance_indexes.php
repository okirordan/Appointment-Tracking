<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->index(['direction', 'deleted_at', 'received_date', 'id'], 'mail_incoming_list_index');
            $table->index(['direction', 'deleted_at', 'sent_date', 'id'], 'mail_outgoing_list_index');
            $table->index(['direction', 'task_id', 'status', 'deleted_at'], 'mail_assignment_summary_index');
            $table->index(['deleted_at', 'financial_year'], 'mail_financial_year_options_index');
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropIndex('mail_incoming_list_index');
            $table->dropIndex('mail_outgoing_list_index');
            $table->dropIndex('mail_assignment_summary_index');
            $table->dropIndex('mail_financial_year_options_index');
        });
    }
};
