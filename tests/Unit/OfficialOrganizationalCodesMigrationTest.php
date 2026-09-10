<?php

namespace Tests\Unit;

use App\Models\AnnotationTitle;
use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficialOrganizationalCodesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renames_legacy_ministerial_codes_without_replacing_records(): void
    {
        $expectedCodes = [
            'OMES' => 'MES',
            'OSMS' => 'MSE/S',
            'OSMPE' => 'MSE/PE',
            'OSMHE' => 'MSE/HE',
        ];
        $ids = [];

        foreach ($expectedCodes as $legacyCode => $canonicalCode) {
            $unit = OrganizationalUnit::query()->whereIn('code', [$legacyCode, $canonicalCode])->first();
            if ($unit !== null && $unit->code !== $legacyCode) {
                $unit->update(['code' => $legacyCode]);
            } elseif ($unit === null) {
                $unit = OrganizationalUnit::create([
                    'type' => 'office',
                    'name' => "Legacy {$legacyCode} office",
                    'code' => $legacyCode,
                    'active' => true,
                ]);
            }

            $ids[$canonicalCode] = $unit->id;
        }

        $migration = require database_path('migrations/2026_09_10_000002_standardize_official_organizational_codes.php');
        $migration->up();
        $migration->up();

        foreach ($expectedCodes as $legacyCode => $canonicalCode) {
            $this->assertDatabaseHas('organizational_units', [
                'id' => $ids[$canonicalCode],
                'code' => $canonicalCode,
                'active' => true,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseMissing('organizational_units', ['code' => $legacyCode]);
        }
    }

    public function test_it_preserves_referenced_records_when_the_canonical_office_already_exists(): void
    {
        $canonical = OrganizationalUnit::query()->where('code', 'MES')->firstOrFail();
        $legacy = OrganizationalUnit::create([
            'type' => 'office',
            'name' => 'Legacy Minister Office',
            'code' => 'OMES',
            'active' => true,
        ]);

        $migration = require database_path('migrations/2026_09_10_000002_standardize_official_organizational_codes.php');
        $migration->up();

        $this->assertDatabaseHas('organizational_units', [
            'id' => $canonical->id,
            'code' => 'MES',
            'active' => true,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('organizational_units', [
            'id' => $legacy->id,
            'code' => 'OMES',
            'active' => true,
            'deleted_at' => null,
        ]);
    }

    public function test_it_corrects_the_primary_education_title_directory_shorthand(): void
    {
        $entries = [
            'MSE/P' => 'MSE/PE',
            'MSE/P-SECRETARY' => 'MSE/PE-SECRETARY',
        ];
        $ids = [];

        foreach ($entries as $legacy => $canonical) {
            $normalizedLegacy = preg_replace('/[^a-z0-9]+/', '', strtolower($legacy));
            $normalizedCanonical = preg_replace('/[^a-z0-9]+/', '', strtolower($canonical));
            $title = AnnotationTitle::query()
                ->whereIn('normalized_shorthand', [$normalizedLegacy, $normalizedCanonical])
                ->firstOrFail();
            $title->update(['shorthand' => $legacy]);
            $ids[$canonical] = $title->id;
        }

        $migration = require database_path('migrations/2026_09_10_000002_standardize_official_organizational_codes.php');
        $migration->up();

        foreach ($entries as $legacy => $canonical) {
            $this->assertDatabaseHas('annotation_titles', [
                'id' => $ids[$canonical],
                'shorthand' => $canonical,
                'normalized_shorthand' => preg_replace('/[^a-z0-9]+/', '', strtolower($canonical)),
                'full_title' => str_replace('MSE/P', 'MSE/PE', str_replace('-SECRETARY', ' - Secretary', $legacy)),
                'active' => true,
            ]);
        }
    }

    public function test_it_repairs_a_canonical_primary_education_shorthand_with_a_legacy_full_title(): void
    {
        $title = AnnotationTitle::query()->where('normalized_shorthand', 'msepe')->firstOrFail();
        $title->update(['full_title' => 'MSE/P']);

        $migration = require database_path('migrations/2026_09_10_000002_standardize_official_organizational_codes.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('annotation_titles', [
            'id' => $title->id,
            'shorthand' => 'MSE/PE',
            'normalized_shorthand' => 'msepe',
            'full_title' => 'MSE/PE',
            'normalized_full_title' => 'msepe',
            'active' => true,
        ]);
    }
}
