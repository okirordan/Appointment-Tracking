<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'administration' => [
                'admin.access' => 'Access system administration',
                'users.manage' => 'Create and edit user accounts',
                'users.delete' => 'Soft-delete and restore user accounts',
                'roles.manage' => 'Manage roles and permissions',
                'hierarchy.manage' => 'Manage units, positions and reporting lines',
                'audit.view' => 'View audit and change history',
            ],
            'assignments' => [
                'assignments.view.all' => 'View every assignment',
                'assignments.view.scope' => 'View assignments in organizational scope',
                'assignments.create' => 'Create assignments',
                'assignments.delegate' => 'Delegate assignments',
                'assignments.direct' => 'Skip hierarchy levels when assigning',
                'assignments.update' => 'Provide progress and submissions',
                'assignments.review' => 'Review assignment submissions',
                'assignments.approve' => 'Approve assignment submissions',
                'assignments.return' => 'Return submissions for correction',
                'assignments.reject' => 'Reject assignment submissions',
                'assignments.reassign' => 'Reassign active workflow steps',
                'assignments.escalate' => 'Escalate overdue assignments',
            ],
            'registry' => [
                'mail.view' => 'View permitted mail registers',
                'mail.manage' => 'Record and update mail register entries',
                'mail.assign' => 'Convert incoming mail into an assignment',
                'mail.view.sensitive' => 'View confidential and restricted correspondence',
            ],
            'oversight' => [
                'reports.view' => 'View reports and performance dashboards',
                'reports.export' => 'Export authorized reports',
            ],
        ];

        foreach ($permissions as $group => $items) {
            foreach ($items as $name => $description) {
                Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['group_name' => $group, 'description' => $description],
                );
            }
        }

        $defaults = [
            RoleEnum::Sysadmin->value => array_keys(array_merge(
                $permissions['administration'],
                $permissions['assignments'],
                $permissions['oversight'],
            )),
            RoleEnum::Ps->value => ['assignments.view.all', 'assignments.create', 'assignments.delegate', 'assignments.direct', 'assignments.review', 'assignments.approve', 'assignments.return', 'assignments.reject', 'assignments.reassign', 'assignments.escalate', 'mail.view', 'mail.manage', 'mail.assign', 'reports.view', 'reports.export'],
            RoleEnum::Clerk->value => ['assignments.view.scope', 'assignments.create', 'assignments.delegate', 'mail.view', 'mail.manage', 'mail.assign'],
            RoleEnum::Commissioner->value => ['assignments.view.scope', 'assignments.create', 'assignments.delegate', 'assignments.direct', 'assignments.update', 'assignments.review', 'assignments.approve', 'assignments.return', 'assignments.reject', 'assignments.reassign', 'reports.view'],
            RoleEnum::Secretary->value => ['assignments.view.scope', 'assignments.update', 'mail.view', 'reports.view'],
            RoleEnum::Officer->value => ['assignments.view.scope', 'assignments.update'],
        ];

        foreach (RoleEnum::cases() as $role) {
            $model = Role::findOrCreate($role->value, 'web');
            $model->forceFill([
                'display_name' => $role->label(),
                'description' => 'Built-in compatibility role.',
                'hierarchy_level' => match ($role) {
                    RoleEnum::Sysadmin => 0,
                    RoleEnum::Ps => 10,
                    RoleEnum::Clerk, RoleEnum::Commissioner => 20,
                    RoleEnum::Secretary => 30,
                    RoleEnum::Officer => 100,
                },
                'is_active' => true,
                'is_system' => true,
            ])->save();
            $model->syncPermissions($defaults[$role->value]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
