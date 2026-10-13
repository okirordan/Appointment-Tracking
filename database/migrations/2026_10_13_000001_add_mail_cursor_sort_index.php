<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('mail_records', function (Blueprint $table) {
            $table->date('cursor_received_date')
                ->storedAs("COALESCE(received_date, '1000-01-01')");
            $table->index(
                ['direction', 'deleted_at', 'cursor_received_date', 'id'],
                'mail_cursor_received_index',
            );
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropIndex('mail_cursor_received_index');
            $table->dropColumn('cursor_received_date');
        });
    }
};
