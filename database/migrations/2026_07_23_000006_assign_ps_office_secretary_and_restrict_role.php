<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $secretaryRoleId = DB::table('roles')->where('name', 'secretary')->value('id');
        $clerkRoleId = DB::table('roles')->where('name', 'clerk')->value('id');
        if ($secretaryRoleId === null) {
            return;
        }

        if ($clerkRoleId !== null) {
            DB::table('roles')->where('id', $clerkRoleId)->update([
                'display_name' => 'Registry Clerk',
                'description' => 'Registry capture and data-entry access without Permanent Secretary authority.',
            ]);
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'mail.view.sensitive', 'guard_name' => 'web'],
            [
                'group_name' => 'registry',
                'description' => 'View confidential and restricted correspondence',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $baselinePermissions = DB::table('permissions')
            ->whereIn('name', ['assignments.view.scope', 'assignments.update', 'mail.view', 'reports.view'])
            ->pluck('id');
        DB::table('role_has_permissions')->where('role_id', $secretaryRoleId)->delete();
        foreach ($baselinePermissions as $permissionId) {
            DB::table('role_has_permissions')->insert([
                'permission_id' => $permissionId,
                'role_id' => $secretaryRoleId,
            ]);
        }

        DB::table('users')
            ->where(fn ($query) => $query
                ->where('full_name', 'PS Secretary')
                ->orWhere('title', 'PS Data Entry Clerk'))
            ->where('role', 'clerk')
            ->update(['title' => 'Registry Clerk', 'updated_at' => $now]);

        $gorreti = DB::table('users')
            ->where('employee_number', '14208')
            ->orWhere('full_name', 'Gorreti Namukwaya')
            ->first();
        $permanentSecretary = DB::table('users')->where('role', 'ps')->where('active', true)->orderBy('id')->first();
        if ($gorreti === null || $permanentSecretary === null) {
            return;
        }

        $oldRoleId = DB::table('roles')->where('name', $gorreti->role)->value('id');
        $officialTitle = 'Senior Personal Secretary to the Permanent Secretary';
        $officeId = DB::table('organizational_units')
            ->where('name', 'like', '%Office of the Permanent Secretary%')
            ->where('active', true)
            ->value('id');

        DB::table('users')->where('id', $gorreti->id)->update([
            'title' => $officialTitle,
            'role' => 'secretary',
            'supervisor_user_id' => $permanentSecretary->id,
            'department_id' => $permanentSecretary->department_id,
            'division_id' => $permanentSecretary->division_id,
            'updated_at' => $now,
        ]);
        DB::table('model_has_roles')
            ->where('model_type', 'App\\Models\\User')
            ->where('model_id', $gorreti->id)
            ->delete();
        DB::table('model_has_roles')->insert([
            'role_id' => $secretaryRoleId,
            'model_type' => 'App\\Models\\User',
            'model_id' => $gorreti->id,
        ]);

        DB::table('secretary_office_attachments')
            ->where('secretary_user_id', $gorreti->id)
            ->where('active', true)
            ->update([
                'active' => false,
                'ends_at' => $now,
                'updated_at' => $now,
            ]);
        $attachmentId = DB::table('secretary_office_attachments')->insertGetId([
            'secretary_user_id' => $gorreti->id,
            'supervisor_user_id' => $permanentSecretary->id,
            'organizational_unit_id' => $officeId,
            'official_job_title' => $officialTitle,
            'starts_at' => $now,
            'ends_at' => null,
            'delegated_actions_permitted' => false,
            'delegated_permissions' => json_encode([]),
            'active' => true,
            'created_by_user_id' => null,
            'ended_by_user_id' => null,
            'reason' => 'Approved attachment to the Office of the Permanent Secretary.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_position_changes')->insert([
            'user_id' => $gorreti->id,
            'previous_position_id' => null,
            'new_position_id' => null,
            'previous_role_id' => $oldRoleId,
            'new_role_id' => $secretaryRoleId,
            'previous_title' => $gorreti->title,
            'new_title' => $officialTitle,
            'effective_date' => $now->toDateString(),
            'changed_at' => $now,
            'changed_by_user_id' => null,
            'reason' => 'Corrected the PS office secretary title and office attachment.',
        ]);

        DB::table('audit_logs')->insert([
            'actor_user_id' => null,
            'actor_name_snapshot' => 'System',
            'category' => 'user',
            'action' => 'Assigned Gorreti Namukwaya as Senior Personal Secretary to the Permanent Secretary',
            'target_type' => 'SecretaryOfficeAttachment',
            'target_id' => $attachmentId,
            'metadata_json' => json_encode([
                'previous_title' => $gorreti->title,
                'new_title' => $officialTitle,
                'supervisor' => $permanentSecretary->full_name,
                'delegated_permissions' => [],
                'effective_date' => $now->toDateString(),
            ]),
            'outcome' => 'success',
            'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Historical office attachments and security corrections are not reversed.
    }
};
