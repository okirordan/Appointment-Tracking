<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class PsCrossDepartmentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_key_names_fit_mysql_identifier_limit(): void
    {
        $migration = require database_path('migrations/2026_09_09_000001_add_ps_cross_department_correspondence_tracking.php');
        $reflection = new ReflectionClass($migration);
        $foreignKeyGroups = [
            $reflection->getConstant('CORRESPONDENCE_FOREIGN_KEYS'),
            $reflection->getConstant('UPDATE_FOREIGN_KEYS'),
        ];

        $this->assertContainsOnly('array', $foreignKeyGroups);

        $foreignKeys = collect($foreignKeyGroups)->flatMap(fn (array $group) => $group);

        $this->assertCount(5, $foreignKeys);

        foreach ($foreignKeys as $foreignKey) {
            $this->assertIsArray($foreignKey);
            $this->assertArrayHasKey('name', $foreignKey);
            $this->assertLessThanOrEqual(
                64,
                strlen($foreignKey['name']),
                "Foreign key [{$foreignKey['name']}] exceeds MySQL's 64-character identifier limit.",
            );
        }
    }

    public function test_migration_can_roll_back_and_reapply_cleanly(): void
    {
        $migration = require database_path('migrations/2026_09_09_000001_add_ps_cross_department_correspondence_tracking.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('correspondences', 'current_holder_organizational_unit_id'));
        $this->assertFalse(Schema::hasColumn('correspondence_updates', 'represented_organizational_unit_id'));
        $this->assertFalse(Schema::hasColumn('correspondence_updates', 'responsible_user_id'));
        $this->assertFalse(Schema::hasColumn('correspondence_updates', 'entry_method'));

        $migration->up();

        $this->assertTrue(Schema::hasForeignKey('correspondences', ['current_holder_organizational_unit_id']));
        $this->assertTrue(Schema::hasForeignKey('correspondence_updates', ['from_organizational_unit_id']));
        $this->assertTrue(Schema::hasForeignKey('correspondence_updates', ['to_organizational_unit_id']));
        $this->assertTrue(Schema::hasForeignKey('correspondence_updates', ['represented_organizational_unit_id']));
        $this->assertTrue(Schema::hasForeignKey('correspondence_updates', ['responsible_user_id']));
        $this->assertTrue(Schema::hasIndex('correspondence_updates', 'correspondence_update_movement_idx'));
    }
}
