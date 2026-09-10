<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'ps_office_cross_department_recording';

    private const CORRESPONDENCE_FOREIGN_KEYS = [
        'current_holder_organizational_unit_id' => [
            'name' => 'corr_current_holder_unit_fk',
            'table' => 'organizational_units',
        ],
    ];

    private const UPDATE_FOREIGN_KEYS = [
        'from_organizational_unit_id' => [
            'name' => 'corr_updates_from_unit_fk',
            'table' => 'organizational_units',
        ],
        'to_organizational_unit_id' => [
            'name' => 'corr_updates_to_unit_fk',
            'table' => 'organizational_units',
        ],
        'represented_organizational_unit_id' => [
            'name' => 'corr_updates_represented_unit_fk',
            'table' => 'organizational_units',
        ],
        'responsible_user_id' => [
            'name' => 'corr_updates_responsible_user_fk',
            'table' => 'users',
        ],
    ];

    public function up(): void
    {
        $this->ensureCorrespondenceSchema();
        $this->ensureCorrespondenceUpdateSchema();

        $this->backfillCurrentHolders();

        $attributes = [
            'name' => self::PERMISSION,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('permissions', 'group_name')) {
            $attributes['group_name'] = 'registry';
        }
        if (Schema::hasColumn('permissions', 'description')) {
            $attributes['description'] = 'Record PS Office correspondence movements on behalf of internal organizational units';
        }
        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            $attributes,
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureCorrespondenceSchema(): void
    {
        if (! Schema::hasColumn('correspondences', 'current_holder_organizational_unit_id')) {
            Schema::table('correspondences', function (Blueprint $table) {
                $table->foreignId('current_holder_organizational_unit_id')
                    ->nullable()
                    ->after('organizational_unit_id');
            });
        }

        $this->ensureForeignKeys('correspondences', self::CORRESPONDENCE_FOREIGN_KEYS);

        if (! Schema::hasIndex('correspondences', 'correspondence_holder_status_idx')) {
            Schema::table('correspondences', function (Blueprint $table) {
                $table->index(['current_holder_organizational_unit_id', 'current_status'], 'correspondence_holder_status_idx');
            });
        }
    }

    private function ensureCorrespondenceUpdateSchema(): void
    {
        if (! Schema::hasColumn('correspondence_updates', 'from_organizational_unit_id')) {
            Schema::table('correspondence_updates', function (Blueprint $table) {
                $table->foreignId('from_organizational_unit_id')->nullable()->after('task_id');
            });
        }
        if (! Schema::hasColumn('correspondence_updates', 'to_organizational_unit_id')) {
            Schema::table('correspondence_updates', function (Blueprint $table) {
                $table->foreignId('to_organizational_unit_id')->nullable()->after('from_organizational_unit_id');
            });
        }
        if (! Schema::hasColumn('correspondence_updates', 'represented_organizational_unit_id')) {
            Schema::table('correspondence_updates', function (Blueprint $table) {
                $table->foreignId('represented_organizational_unit_id')->nullable()->after('to_organizational_unit_id');
            });
        }
        if (! Schema::hasColumn('correspondence_updates', 'responsible_user_id')) {
            Schema::table('correspondence_updates', function (Blueprint $table) {
                $table->foreignId('responsible_user_id')->nullable()->after('represented_organizational_unit_id');
            });
        }
        if (! Schema::hasColumn('correspondence_updates', 'entry_method')) {
            Schema::table('correspondence_updates', function (Blueprint $table) {
                $table->string('entry_method', 40)->default('normal')->after('type');
            });
        }
        if (! Schema::hasColumn('correspondence_updates', 'occurred_at')) {
            Schema::table('correspondence_updates', function (Blueprint $table) {
                $table->timestamp('occurred_at')->nullable()->after('recipient_summary');
            });
        }
        if (! Schema::hasColumn('correspondence_updates', 'recorded_at')) {
            Schema::table('correspondence_updates', function (Blueprint $table) {
                $table->timestamp('recorded_at')->nullable()->after('occurred_at');
            });
        }

        $this->ensureForeignKeys('correspondence_updates', self::UPDATE_FOREIGN_KEYS);

        $indexes = [
            'correspondence_updates_entry_method_index' => ['entry_method'],
            'correspondence_updates_occurred_at_index' => ['occurred_at'],
            'correspondence_updates_recorded_at_index' => ['recorded_at'],
            'correspondence_update_movement_idx' => [
                'correspondence_id',
                'entry_method',
                'from_organizational_unit_id',
                'to_organizational_unit_id',
            ],
        ];

        foreach ($indexes as $name => $columns) {
            if (Schema::hasIndex('correspondence_updates', $name)) {
                continue;
            }

            Schema::table('correspondence_updates', function (Blueprint $table) use ($columns, $name) {
                $table->index($columns, $name);
            });
        }
    }

    private function ensureForeignKeys(string $tableName, array $foreignKeys): void
    {
        foreach ($foreignKeys as $column => $foreignKey) {
            if (Schema::hasForeignKey($tableName, [$column])) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($column, $foreignKey) {
                $table->foreign($column, $foreignKey['name'])
                    ->references('id')
                    ->on($foreignKey['table'])
                    ->nullOnDelete();
            });
        }
    }

    private function backfillCurrentHolders(): void
    {
        DB::table('correspondences')
            ->whereNotIn('current_status', ['closed', 'filed'])
            ->whereNotExists(fn ($recipient) => $recipient
                ->selectRaw('1')
                ->from('correspondence_recipients')
                ->whereColumn('correspondence_recipients.correspondence_id', 'correspondences.id')
                ->where('correspondence_recipients.recipient_type', 'to')
                ->where('correspondence_recipients.active', true))
            ->update(['current_holder_organizational_unit_id' => DB::raw('organizational_unit_id')]);

        DB::table('correspondences')
            ->whereNotIn('current_status', ['closed', 'filed'])
            ->whereExists(fn ($recipient) => $recipient
                ->selectRaw('1')
                ->from('correspondence_recipients')
                ->whereColumn('correspondence_recipients.correspondence_id', 'correspondences.id')
                ->where('correspondence_recipients.recipient_type', 'to')
                ->where('correspondence_recipients.active', true))
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($correspondences): void {
                $ids = $correspondences->pluck('id');
                $recipients = DB::table('correspondence_recipients')
                    ->whereIn('correspondence_id', $ids)
                    ->where('recipient_type', 'to')
                    ->where('active', true)
                    ->get(['correspondence_id', 'organizational_unit_id', 'department_id', 'user_id'])
                    ->groupBy('correspondence_id');
                $allRecipients = $recipients->flatten();
                $departmentUnits = DB::table('departments')
                    ->whereIn('id', $allRecipients->pluck('department_id')->filter()->unique())
                    ->pluck('organizational_unit_id', 'id');
                $userUnits = DB::table('users')
                    ->whereIn('id', $allRecipients->pluck('user_id')->filter()->unique())
                    ->pluck('organizational_unit_id', 'id');

                foreach ($correspondences as $correspondence) {
                    $holderIds = collect($recipients->get($correspondence->id, []))
                        ->map(fn ($recipient) => $recipient->organizational_unit_id
                            ?? $departmentUnits->get($recipient->department_id)
                            ?? $userUnits->get($recipient->user_id))
                        ->unique();

                    if ($holderIds->count() !== 1 || $holderIds->first() === null) {
                        continue;
                    }

                    DB::table('correspondences')->where('id', $correspondence->id)->update([
                        'current_holder_organizational_unit_id' => $holderIds->first(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        $this->dropForeignKeys('correspondence_updates', array_keys(self::UPDATE_FOREIGN_KEYS));
        $this->dropIndexIfExists('correspondence_updates', 'correspondence_update_movement_idx');
        $this->dropIndexIfExists('correspondence_updates', 'correspondence_updates_entry_method_index');
        $this->dropIndexIfExists('correspondence_updates', 'correspondence_updates_occurred_at_index');
        $this->dropIndexIfExists('correspondence_updates', 'correspondence_updates_recorded_at_index');
        $this->dropColumnsIfPresent('correspondence_updates', [
            'responsible_user_id',
            'represented_organizational_unit_id',
            'to_organizational_unit_id',
            'from_organizational_unit_id',
            'entry_method',
            'occurred_at',
            'recorded_at',
        ]);

        $this->dropForeignKeys('correspondences', array_keys(self::CORRESPONDENCE_FOREIGN_KEYS));
        $this->dropIndexIfExists('correspondences', 'correspondence_holder_status_idx');
        $this->dropColumnsIfPresent('correspondences', ['current_holder_organizational_unit_id']);

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id');
        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function dropForeignKeys(string $tableName, array $columns): void
    {
        foreach ($columns as $column) {
            $foreignKey = collect(Schema::getForeignKeys($tableName))
                ->first(fn (array $key) => $key['columns'] === [$column]);

            if ($foreignKey === null) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($column, $foreignKey) {
                $table->dropForeign($foreignKey['name'] ?? [$column]);
            });
        }
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        if (! Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }

    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($tableName, $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existingColumns) {
            $table->dropColumn($existingColumns);
        });
    }
};
