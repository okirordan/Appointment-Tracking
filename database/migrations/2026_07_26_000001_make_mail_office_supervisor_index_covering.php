<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The report aggregates in ReportService always filter on
     * office_supervisor_user_id together with the soft-delete column, but
     * deleted_at was missing from the index — so MySQL matched on the index
     * and then read all 73k rows off disk just to test deleted_at. Adding it
     * makes the index covering ("Using index"), which measured 2,943 ms down
     * to 181 ms for a single one of those six counts.
     *
     * The replaced index is a strict prefix of the new one, so it is dropped
     * rather than kept: a redundant copy only competes for buffer-pool pages.
     * It has to be dropped *after* the replacement exists, because the
     * office_supervisor_user_id foreign key needs an index on that column at
     * all times and MySQL refuses to leave it uncovered.
     */
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->index(
                ['office_supervisor_user_id', 'deleted_at', 'direction', 'status'],
                'mail_office_supervisor_scope_idx',
            );
        });

        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropIndex('mail_office_direction_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->index(
                ['office_supervisor_user_id', 'direction', 'status'],
                'mail_office_direction_status_idx',
            );
        });

        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropIndex('mail_office_supervisor_scope_idx');
        });
    }
};
