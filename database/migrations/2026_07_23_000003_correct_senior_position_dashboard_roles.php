<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('name', ['ps', 'commissioner'])->pluck('id', 'name');
        if (! isset($roles['ps'], $roles['commissioner'])) {
            return;
        }

        $systemRoleFor = static function (?string $title): ?string {
            $normalized = mb_strtolower(trim((string) $title));
            if (str_contains($normalized, 'permanent secretary') || str_contains($normalized, 'permenent secretary')) {
                return 'ps';
            }
            if (preg_match('/^(director|commissioner|assistant commissioner|under[\s-]?secretary|principal assistant secretary)\b/u', $normalized)) {
                return 'commissioner';
            }

            return null;
        };

        foreach (DB::table('positions')->select(['id', 'title', 'role_id'])->get() as $position) {
            $roleName = $systemRoleFor($position->title);
            if ($roleName !== null && (int) $position->role_id !== (int) $roles[$roleName]) {
                DB::table('positions')->where('id', $position->id)->update([
                    'role_id' => $roles[$roleName],
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (DB::table('users')->select(['id', 'title', 'role'])->get() as $user) {
            $roleName = $systemRoleFor($user->title);
            if ($roleName === null || $user->role === $roleName) {
                continue;
            }

            $oldRoleId = DB::table('roles')->where('name', $user->role)->value('id');
            $positionId = DB::table('user_positions')
                ->where('user_id', $user->id)
                ->where('is_primary', true)
                ->where('active', true)
                ->orderByDesc('id')
                ->value('position_id');

            DB::table('users')->where('id', $user->id)->update([
                'role' => $roleName,
                'updated_at' => now(),
            ]);
            DB::table('model_has_roles')
                ->where('model_type', 'App\\Models\\User')
                ->where('model_id', $user->id)
                ->delete();
            DB::table('model_has_roles')->insert([
                'role_id' => $roles[$roleName],
                'model_type' => 'App\\Models\\User',
                'model_id' => $user->id,
            ]);
            DB::table('user_position_changes')->insert([
                'user_id' => $user->id,
                'previous_position_id' => $positionId,
                'new_position_id' => $positionId,
                'previous_role_id' => $oldRoleId,
                'new_role_id' => $roles[$roleName],
                'previous_title' => $user->title,
                'new_title' => $user->title,
                'effective_date' => now()->toDateString(),
                'changed_at' => now(),
                'changed_by_user_id' => null,
                'reason' => 'Corrected dashboard eligibility for the current senior position.',
            ]);
        }
    }

    public function down(): void
    {
        // Historical role corrections are intentionally not reversed.
    }
};
