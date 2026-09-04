<?php

namespace Tests\Unit\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpandAuditLogCategoryLengthMigrationTest extends TestCase
{
    public function test_migration_expands_the_audit_category_column_for_descriptive_categories(): void
    {
        $migration = require database_path('migrations/2026_09_04_000001_expand_audit_log_category_length.php');
        $connection = DB::connection();

        Schema::shouldReceive('table')
            ->once()
            ->with('audit_logs', \Mockery::on(function (callable $callback) use ($connection): bool {
                $table = new Blueprint($connection, 'audit_logs');
                $callback($table);

                $category = collect($table->getColumns())->firstWhere('name', 'category');

                $this->assertNotNull($category);
                $this->assertSame(64, $category->length);
                $this->assertTrue($category->change);

                return true;
            }));

        $migration->up();
    }
}
