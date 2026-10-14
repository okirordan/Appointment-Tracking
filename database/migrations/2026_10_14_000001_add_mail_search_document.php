<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'ft_mail_search';

    public function up(): void
    {
        if (! Schema::hasColumn('mail_records', 'search_text')) {
            Schema::table('mail_records', fn (Blueprint $table) => $table->longText('search_text')->nullable());
        }

        if (DB::connection()->getDriverName() === 'mysql' && ! Schema::hasIndex('mail_records', self::INDEX)) {
            Schema::table('mail_records', fn (Blueprint $table) => $table->fullText('search_text', self::INDEX));
        }

        // The four requested prefix lookup indexes already exist on the
        // current schema, including a unique register_number index.
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql' && Schema::hasIndex('mail_records', self::INDEX)) {
            Schema::table('mail_records', fn (Blueprint $table) => $table->dropFullText(self::INDEX));
        }

        if (Schema::hasColumn('mail_records', 'search_text')) {
            Schema::table('mail_records', fn (Blueprint $table) => $table->dropColumn('search_text'));
        }
    }
};
