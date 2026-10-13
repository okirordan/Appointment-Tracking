<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            // Outgoing includes forwarded incoming records, so direction cannot
            // be the leading index column for this mixed mailbox order.
            $table->index(['deleted_at', 'updated_at', 'id'], 'mail_cursor_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropIndex('mail_cursor_updated_index');
        });
    }
};
