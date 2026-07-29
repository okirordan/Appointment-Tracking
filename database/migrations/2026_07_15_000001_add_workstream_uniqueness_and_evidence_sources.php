<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstreams', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->after('name');
        });

        $canonicalByName = [];

        $workstreams = DB::table('workstreams')->get()->sortBy(fn ($workstream) => sprintf(
            '%d%d%020d',
            $workstream->deleted_at === null ? 0 : 1,
            $workstream->active ? 0 : 1,
            $workstream->id,
        ));

        foreach ($workstreams as $workstream) {
            $normalized = Str::lower(Str::squish((string) $workstream->name));

            if (isset($canonicalByName[$normalized])) {
                DB::table('tasks')
                    ->where('workstream_id', $workstream->id)
                    ->update(['workstream_id' => $canonicalByName[$normalized]]);
                DB::table('workstreams')->where('id', $workstream->id)->delete();

                continue;
            }

            $canonicalByName[$normalized] = $workstream->id;
            DB::table('workstreams')->where('id', $workstream->id)->update(['normalized_name' => $normalized]);
        }

        Schema::table('workstreams', function (Blueprint $table) {
            $table->unique('normalized_name');
        });

        Schema::table('evidence_attachments', function (Blueprint $table) {
            $table->string('source_type', 20)->default('file')->after('history_id')->index();
            $table->text('external_url')->nullable()->after('storage_key');
        });
    }

    public function down(): void
    {
        Schema::table('evidence_attachments', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'external_url']);
        });

        Schema::table('workstreams', function (Blueprint $table) {
            $table->dropUnique(['normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
