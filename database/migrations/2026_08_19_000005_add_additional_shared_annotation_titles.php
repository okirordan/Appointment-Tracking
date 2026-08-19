<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<array{shorthand: string, full_title: ?string}> */
    private const ENTRIES = [
        ['shorthand' => 'MSE/P', 'full_title' => null],
        ['shorthand' => 'MES(Ai)', 'full_title' => 'Acting Minister of Education and Sports'],
        ['shorthand' => 'MSE/S', 'full_title' => null],
        ['shorthand' => 'FL-MES', 'full_title' => 'Full Minister of Education and Sports'],
        ['shorthand' => 'US/SPS/FL-MES', 'full_title' => 'US/SPS/FL-MES'],
        ['shorthand' => 'TA/FL-MES', 'full_title' => 'TA/FL-MES'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ENTRIES as $entry) {
            $shorthand = $this->displayShorthand($entry['shorthand']);
            $normalizedShorthand = $this->normalize($shorthand);
            $existing = DB::table('annotation_titles')
                ->where('normalized_shorthand', $normalizedShorthand)
                ->first();

            if ($existing !== null) {
                $updates = [
                    'shorthand' => $shorthand,
                    'active' => true,
                    'updated_at' => $now,
                ];

                if ($entry['full_title'] !== null) {
                    $updates['full_title'] = $entry['full_title'];
                    $updates['normalized_full_title'] = $this->normalize($entry['full_title']);
                }

                DB::table('annotation_titles')->where('id', $existing->id)->update($updates);

                continue;
            }

            $fullTitle = $entry['full_title'] ?? $shorthand;
            DB::table('annotation_titles')->insert([
                'shorthand' => $shorthand,
                'normalized_shorthand' => $normalizedShorthand,
                'full_title' => $fullTitle,
                'normalized_full_title' => $this->normalize($fullTitle),
                'active' => true,
                'created_by_user_id' => null,
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Shared directory entries may be referenced by correspondence. Retain them on rollback.
    }

    private function displayShorthand(string $value): string
    {
        return Str::upper(preg_replace('/\s*([\/&-])\s*/u', '$1', trim($value)) ?? trim($value));
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii(trim($value)))) ?? '';
    }
};
