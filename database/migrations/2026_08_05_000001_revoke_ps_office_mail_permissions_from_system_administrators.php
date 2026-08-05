<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const MAIL_PERMISSIONS = [
        'mail.view',
        'mail.manage',
        'mail.assign',
        'mail.view.sensitive',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'sysadmin')->where('guard_name', 'web')->value('id');
        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::MAIL_PERMISSIONS)
            ->pluck('id');

        DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'sysadmin')->where('guard_name', 'web')->value('id');
        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::MAIL_PERMISSIONS)
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
