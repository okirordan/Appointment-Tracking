<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $requiredTables = ['departments', 'organizational_units', 'positions', 'users', 'user_positions', 'roles', 'model_has_roles', 'recipient_aliases'];
        if (collect($requiredTables)->contains(fn (string $table) => ! Schema::hasTable($table))) {
            return;
        }

        DB::transaction(function (): void {
            $department = DB::table('departments')->where('code', 'LEIT')->whereNull('deleted_at')->first(['id']);
            $position = $department === null ? null : DB::table('positions')
                ->join('organizational_units', 'organizational_units.id', '=', 'positions.organizational_unit_id')
                ->where('positions.title', 'Commissioner – Library, E-Learning and Information Technology')
                ->where('positions.active', true)
                ->whereNull('positions.deleted_at')
                ->where('organizational_units.department_id', $department->id)
                ->where('organizational_units.active', true)
                ->whereNull('organizational_units.deleted_at')
                ->first([
                    'positions.id', 'positions.role_id', 'positions.title',
                    'organizational_units.id as organizational_unit_id', 'organizational_units.division_id',
                ]);
            $officer = DB::table('users')
                ->whereNull('deleted_at')
                ->where(fn ($query) => $query
                    ->where('employee_number', '13524')
                    ->orWhere('full_name', 'Patrick Emmanuel Muinda'))
                ->first(['id', 'full_name']);
            $roleName = $position === null ? null : DB::table('roles')->where('id', $position->role_id)->value('name');

            if ($department === null || $position === null || $officer === null || $roleName === null) {
                return;
            }

            $now = now();
            DB::table('users')->where('id', $officer->id)->update([
                'title' => $position->title,
                'role' => $roleName,
                'department_id' => $department->id,
                'division_id' => $position->division_id,
                'organizational_unit_id' => $position->organizational_unit_id,
                'updated_at' => $now,
            ]);
            DB::table('departments')->where('id', $department->id)->update([
                'head_user_id' => $officer->id,
                'head_name' => $officer->full_name,
                'updated_at' => $now,
            ]);

            DB::table('user_positions')
                ->where('user_id', $officer->id)
                ->where('is_primary', true)
                ->where('active', true)
                ->where('position_id', '!=', $position->id)
                ->update(['active' => false, 'ends_at' => $now, 'updated_at' => $now]);
            $appointment = DB::table('user_positions')
                ->where('user_id', $officer->id)
                ->where('position_id', $position->id)
                ->orderByDesc('id')
                ->first(['id']);
            if ($appointment === null) {
                DB::table('user_positions')->insert([
                    'user_id' => $officer->id,
                    'position_id' => $position->id,
                    'is_primary' => true,
                    'is_acting' => false,
                    'starts_at' => $now,
                    'ends_at' => null,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('user_positions')->where('id', $appointment->id)->update([
                    'is_primary' => true,
                    'ends_at' => null,
                    'active' => true,
                    'updated_at' => $now,
                ]);
            }

            DB::table('model_has_roles')
                ->where('model_type', 'App\\Models\\User')
                ->where('model_id', $officer->id)
                ->delete();
            DB::table('model_has_roles')->insert([
                'role_id' => $position->role_id,
                'model_type' => 'App\\Models\\User',
                'model_id' => $officer->id,
            ]);

            DB::table('recipient_aliases')
                ->where('normalized_alias', 'cleit')
                ->where(fn ($query) => $query
                    ->where('target_type', '!=', 'App\\Models\\Position')
                    ->orWhere('target_id', '!=', $position->id))
                ->update(['active' => false, 'deleted_at' => $now, 'updated_at' => $now]);
            $canonicalAlias = DB::table('recipient_aliases')
                ->where('normalized_alias', 'cleit')
                ->where('target_type', 'App\\Models\\Position')
                ->where('target_id', $position->id)
                ->first(['id']);
            if ($canonicalAlias === null) {
                DB::table('recipient_aliases')->insert([
                    'alias' => 'C/LEIT',
                    'normalized_alias' => 'cleit',
                    'target_type' => 'App\\Models\\Position',
                    'target_id' => $position->id,
                    'active' => true,
                    'deleted_at' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]);
            } else {
                DB::table('recipient_aliases')->where('id', $canonicalAlias->id)->update([
                    'alias' => 'C/LEIT',
                    'active' => true,
                    'deleted_at' => null,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Personnel corrections are intentionally not guessed backwards.
    }
};
