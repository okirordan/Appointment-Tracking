<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SHORTHAND = 'PS/ES';

    private const NORMALIZED_SHORTHAND = 'pses';

    private const FULL_TITLE = 'Permanent Secretary / Education and Sports';

    private const NORMALIZED_FULL_TITLE = 'permanentsecretaryeducationandsports';

    public function up(): void
    {
        $now = now();
        $existing = DB::table('annotation_titles')
            ->where('normalized_shorthand', self::NORMALIZED_SHORTHAND)
            ->first();

        if ($existing !== null) {
            $updates = [
                'shorthand' => self::SHORTHAND,
                'active' => true,
                'updated_at' => $now,
            ];
            $fullTitleOwner = DB::table('annotation_titles')
                ->where('normalized_full_title', self::NORMALIZED_FULL_TITLE)
                ->first();
            if ($fullTitleOwner === null || $fullTitleOwner->id === $existing->id) {
                $updates['full_title'] = self::FULL_TITLE;
                $updates['normalized_full_title'] = self::NORMALIZED_FULL_TITLE;
            }

            DB::table('annotation_titles')
                ->where('id', $existing->id)
                ->update($updates);

            return;
        }

        $matchingTitle = DB::table('annotation_titles')
            ->where('normalized_full_title', self::NORMALIZED_FULL_TITLE)
            ->first();

        if ($matchingTitle !== null) {
            DB::table('annotation_titles')
                ->where('id', $matchingTitle->id)
                ->update([
                    'shorthand' => self::SHORTHAND,
                    'normalized_shorthand' => self::NORMALIZED_SHORTHAND,
                    'full_title' => self::FULL_TITLE,
                    'active' => true,
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('annotation_titles')->insert([
            'shorthand' => self::SHORTHAND,
            'normalized_shorthand' => self::NORMALIZED_SHORTHAND,
            'full_title' => self::FULL_TITLE,
            'normalized_full_title' => self::NORMALIZED_FULL_TITLE,
            'active' => true,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Shared directory data may be referenced by correspondence. Retain it on rollback.
    }
};
