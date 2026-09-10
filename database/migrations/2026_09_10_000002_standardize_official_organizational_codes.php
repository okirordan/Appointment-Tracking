<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<array{legacy: string, canonical: string, name: string}> */
    private const OFFICES = [
        ['legacy' => 'OMES', 'canonical' => 'MES', 'name' => 'Office of the Minister of Education and Sports'],
        ['legacy' => 'OSMS', 'canonical' => 'MSE/S', 'name' => 'Office of the Minister of State for Sports'],
        ['legacy' => 'OSMPE', 'canonical' => 'MSE/PE', 'name' => 'Office of the Minister of State for Primary Education'],
        ['legacy' => 'OSMHE', 'canonical' => 'MSE/HE', 'name' => 'Office of the Minister of State for Higher Education'],
    ];

    /** @var list<array{legacy: string, canonical: string, legacy_full: string, canonical_full: string}> */
    private const ANNOTATION_TITLES = [
        [
            'legacy' => 'MSE/P',
            'canonical' => 'MSE/PE',
            'legacy_full' => 'MSE/P',
            'canonical_full' => 'MSE/PE',
        ],
        [
            'legacy' => 'MSE/P-SECRETARY',
            'canonical' => 'MSE/PE-SECRETARY',
            'legacy_full' => 'MSE/P - Secretary',
            'canonical_full' => 'MSE/PE - Secretary',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('organizational_units')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::OFFICES as $office) {
                $legacy = DB::table('organizational_units')->where('code', $office['legacy'])->first(['id']);
                if ($legacy === null) {
                    continue;
                }

                $canonical = DB::table('organizational_units')->where('code', $office['canonical'])->first(['id']);
                if ($canonical === null) {
                    DB::table('organizational_units')->where('id', $legacy->id)->update([
                        'code' => $office['canonical'],
                        'name' => $office['name'],
                        'active' => true,
                        'deleted_at' => null,
                        'updated_at' => now(),
                    ]);

                    continue;
                }

                // Both IDs may already be referenced. Keep both records intact;
                // presentation normalizes the legacy code to the canonical label.
            }

            if (! Schema::hasTable('annotation_titles')) {
                return;
            }

            foreach (self::ANNOTATION_TITLES as $title) {
                $legacyNormalized = preg_replace('/[^a-z0-9]+/', '', strtolower($title['legacy']));
                $canonicalNormalized = preg_replace('/[^a-z0-9]+/', '', strtolower($title['canonical']));
                $canonicalFullNormalized = preg_replace('/[^a-z0-9]+/', '', strtolower($title['canonical_full']));
                $legacyFullNormalized = preg_replace('/[^a-z0-9]+/', '', strtolower($title['legacy_full']));
                $canonical = DB::table('annotation_titles')
                    ->where('normalized_shorthand', $canonicalNormalized)
                    ->first(['id', 'full_title']);
                if ($canonical !== null
                    && preg_replace('/[^a-z0-9]+/', '', strtolower($canonical->full_title)) === $legacyFullNormalized
                ) {
                    $fullTitleConflict = DB::table('annotation_titles')
                        ->where('normalized_full_title', $canonicalFullNormalized)
                        ->where('id', '!=', $canonical->id)
                        ->exists();
                    if (! $fullTitleConflict) {
                        DB::table('annotation_titles')->where('id', $canonical->id)->update([
                            'full_title' => $title['canonical_full'],
                            'normalized_full_title' => $canonicalFullNormalized,
                            'updated_at' => now(),
                        ]);
                    }
                }
                $legacy = DB::table('annotation_titles')
                    ->where('normalized_shorthand', $legacyNormalized)
                    ->first(['id']);
                if ($legacy === null) {
                    continue;
                }

                if ($canonical === null) {
                    $updates = [
                        'shorthand' => $title['canonical'],
                        'normalized_shorthand' => $canonicalNormalized,
                        'active' => true,
                        'updated_at' => now(),
                    ];
                    $fullTitleConflict = DB::table('annotation_titles')
                        ->where('normalized_full_title', $canonicalFullNormalized)
                        ->where('id', '!=', $legacy->id)
                        ->exists();
                    if (! $fullTitleConflict) {
                        $updates['full_title'] = $title['canonical_full'];
                        $updates['normalized_full_title'] = $canonicalFullNormalized;
                    }
                    DB::table('annotation_titles')->where('id', $legacy->id)->update($updates);

                    continue;
                }

                DB::table('annotation_titles')->where('id', $legacy->id)->update([
                    'active' => false,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Organizational-unit IDs may already be referenced by correspondence.
        // Reintroducing legacy codes or reviving duplicates would be unsafe.
    }
};
