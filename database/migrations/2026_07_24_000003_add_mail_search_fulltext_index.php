<?php

use App\Models\MailRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'mail_records_search_fulltext';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('mail_records', function (Blueprint $table) {
            $table->fullText(MailRecord::SEARCHABLE_TEXT_COLUMNS, self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('mail_records', function (Blueprint $table) {
            $table->dropFullText(self::INDEX_NAME);
        });
    }
};
