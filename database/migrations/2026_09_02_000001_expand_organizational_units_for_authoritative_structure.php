<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_units', function (Blueprint $table) {
            $table->text('description')->nullable()->after('code');
            $table->foreignId('head_user_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
            $table->foreignId('secretary_user_id')->nullable()->after('head_user_id')->constrained('users')->nullOnDelete();
            $table->boolean('is_top_level')->default(false)->after('secretary_user_id')->index();
            $table->unsignedInteger('sort_order')->default(0)->after('is_top_level');
            $table->index(['parent_id', 'active', 'sort_order'], 'organization_parent_status_order_idx');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('organizational_unit_id')->nullable()->after('id')->constrained('organizational_units')->nullOnDelete();
        });
        Schema::table('divisions', function (Blueprint $table) {
            $table->foreignId('organizational_unit_id')->nullable()->after('id')->constrained('organizational_units')->nullOnDelete();
        });

        foreach ([
            'OMES' => 'Office of the Minister of Education and Sports',
            'OSMPE' => 'Office of the Minister of State for Primary Education',
            'OSMHE' => 'Office of the Minister of State for Higher Education',
            'OSMS' => 'Office of the Minister of State for Sports',
        ] as $code => $name) {
            DB::table('organizational_units')->where('code', $code)->update([
                'type' => 'office',
                'name' => $name,
            ]);
        }

        DB::table('organizational_units')->whereNull('parent_id')->update(['is_top_level' => true]);

        DB::table('departments')->orderBy('id')->each(function (object $department): void {
            $unitId = DB::table('organizational_units')
                ->where('department_id', $department->id)
                ->whereNull('division_id')
                ->where('type', 'department')
                ->orderBy('id')
                ->value('id');
            $unitId ??= $this->matchingUnitId('department', $department->code, $department->name);
            if ($unitId !== null) {
                DB::table('departments')->where('id', $department->id)->update(['organizational_unit_id' => $unitId]);
                DB::table('organizational_units')->where('id', $unitId)->update(['department_id' => $department->id]);
                if ($department->head_user_id !== null) {
                    DB::table('organizational_units')->where('id', $unitId)->update(['head_user_id' => $department->head_user_id]);
                }
            }
        });

        DB::table('divisions')->orderBy('id')->each(function (object $division): void {
            $unitId = DB::table('organizational_units')
                ->where('division_id', $division->id)
                ->where('type', 'division')
                ->orderBy('id')
                ->value('id');
            $unitId ??= $this->matchingUnitId('division', $division->code, $division->name);
            if ($unitId !== null) {
                DB::table('divisions')->where('id', $division->id)->update(['organizational_unit_id' => $unitId]);
                DB::table('organizational_units')->where('id', $unitId)->update(['division_id' => $division->id]);
            }
        });

        if (Schema::hasTable('secretary_office_attachments')) {
            DB::table('secretary_office_attachments')
                ->whereNotNull('organizational_unit_id')
                ->where('active', true)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get()
                ->unique('secretary_user_id')
                ->each(fn (object $attachment) => DB::table('organizational_units')
                    ->where('id', $attachment->organizational_unit_id)
                    ->update(['secretary_user_id' => $attachment->secretary_user_id]));
        }

        Schema::table('organizational_units', fn (Blueprint $table) => $table->unique('secretary_user_id'));
    }

    private function matchingUnitId(string $type, ?string $code, string $name): ?int
    {
        if ($code !== null && $code !== '') {
            $unitIds = DB::table('organizational_units')
                ->where('type', $type)
                ->where('code', $code)
                ->limit(2)
                ->pluck('id');

            if ($unitIds->count() === 1) {
                return (int) $unitIds->first();
            }
        }

        $unitIds = DB::table('organizational_units')
            ->where('type', $type)
            ->where('name', $name)
            ->limit(2)
            ->pluck('id');

        return $unitIds->count() === 1 ? (int) $unitIds->first() : null;
    }

    public function down(): void
    {
        foreach ([
            'OMES' => 'Office of the Minister of Education & Sports',
            'OSMPE' => 'Office of the State Minister for Primary Education',
            'OSMHE' => 'Office of the State Minister for Higher Education',
            'OSMS' => 'Office of the State Minister for Sports',
        ] as $code => $name) {
            DB::table('organizational_units')->where('code', $code)->update([
                'type' => 'ministerial_office',
                'name' => $name,
            ]);
        }

        Schema::table('divisions', fn (Blueprint $table) => $table->dropConstrainedForeignId('organizational_unit_id'));
        Schema::table('departments', fn (Blueprint $table) => $table->dropConstrainedForeignId('organizational_unit_id'));

        Schema::table('organizational_units', function (Blueprint $table) {
            $table->dropIndex('organization_parent_status_order_idx');
            $table->dropIndex('organizational_units_is_top_level_index');
            $table->dropUnique('organizational_units_secretary_user_id_unique');
            $table->dropConstrainedForeignId('secretary_user_id');
            $table->dropConstrainedForeignId('head_user_id');
            $table->dropColumn(['description', 'is_top_level', 'sort_order']);
        });
    }
};
