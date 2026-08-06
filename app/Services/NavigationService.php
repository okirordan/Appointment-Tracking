<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;

class NavigationService
{
    /**
     * Role-driven sidebar navigation (PRD §9). Icons are lucide names the
     * frontend resolves; hrefs are generated from named routes so nav and
     * routing can never drift apart.
     *
     * @return list<array{key: string, label: string, icon: string, tone: string, href: string, active: bool}>
     */
    public function forUser(User $user, Request $request): array
    {
        $items = match ($user->role) {
            Role::Sysadmin => [
                ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'home'],
                ['key' => 'admin', 'label' => 'Admin Dashboard', 'icon' => 'layout-dashboard', 'route' => 'admin.dashboard'],
                ['key' => 'users', 'label' => 'User Management', 'icon' => 'users', 'route' => 'admin.users.index'],
                ['key' => 'roles', 'label' => 'Roles & Permissions', 'icon' => 'shield-check', 'route' => 'admin.roles.index'],
                ['key' => 'hierarchy', 'label' => 'Organization Hierarchy', 'icon' => 'network', 'route' => 'admin.hierarchy.index'],
                ['key' => 'recipient-aliases', 'label' => 'Recipient Shorthand', 'icon' => 'tags', 'route' => 'admin.recipient-aliases.index'],
                ['key' => 'departments', 'label' => 'Departments', 'icon' => 'building-2', 'route' => 'admin.departments.index'],
                ['key' => 'divisions', 'label' => 'Divisions', 'icon' => 'building-2', 'route' => 'admin.divisions.index'],
                ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index'],
                ['key' => 'performance', 'label' => 'Performance Monitor', 'icon' => 'check-circle-2', 'route' => 'performance.index'],
                ['key' => 'imports', 'label' => 'Data Imports', 'icon' => 'clipboard-list', 'route' => 'admin.imports.index'],
                ['key' => 'audit', 'label' => 'Audit Log', 'icon' => 'clock', 'route' => 'admin.audit.index'],
                ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'route' => 'admin.settings.index'],
            ],
            Role::Ps => [
                ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'home'],
                ['key' => 'mail', 'label' => 'Mails', 'icon' => 'mail', 'route' => 'mail.incoming.index'],
                ['key' => 'tasks', 'label' => 'All Assignments', 'icon' => 'clipboard-list', 'route' => 'tasks.index'],
                ['key' => 'performance', 'label' => 'Performance Monitor', 'icon' => 'check-circle-2', 'route' => 'performance.index'],
                ['key' => 'correspondence', 'label' => 'Correspondence', 'icon' => 'mail', 'route' => 'correspondence.index'],
                ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index'],
            ],
            Role::Clerk => [
                ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'home'],
                ['key' => 'tasks', 'label' => 'Registry Tasks', 'icon' => 'clipboard-list', 'route' => 'tasks.index'],
                ['key' => 'mail', 'label' => 'Mails', 'icon' => 'mail', 'route' => 'mail.incoming.index'],
                ['key' => 'correspondence', 'label' => 'Correspondence', 'icon' => 'mail', 'route' => 'correspondence.index'],
            ],
            Role::Commissioner => [
                ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'home'],
                ['key' => 'dept', 'label' => 'Department Dashboard', 'icon' => 'building-2', 'route' => 'dept.dashboard'],
                ['key' => 'mail', 'label' => 'Mails', 'icon' => 'mail', 'route' => 'mail.incoming.index'],
                ['key' => 'tasks', 'label' => 'Tasks', 'icon' => 'clipboard-list', 'route' => 'tasks.index'],
                ['key' => 'correspondence', 'label' => 'Correspondence', 'icon' => 'mail', 'route' => 'correspondence.index'],
                ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index'],
                ['key' => 'performance', 'label' => 'Performance Monitor', 'icon' => 'check-circle-2', 'route' => 'performance.index'],
            ],
            Role::Secretary => [
                ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'home'],
                ['key' => 'mail', 'label' => 'Mails', 'icon' => 'mail', 'route' => 'mail.incoming.index'],
                [
                    'key' => 'secretary',
                    'label' => 'Supported Office',
                    'icon' => 'landmark',
                    'route' => $user->currentSecretaryAttachment()->exists() ? 'secretary.dashboard' : 'dept.dashboard',
                ],
                ['key' => 'tasks', 'label' => 'Office Assignments', 'icon' => 'clipboard-list', 'route' => 'tasks.index'],
                ['key' => 'correspondence', 'label' => 'Correspondence', 'icon' => 'mail', 'route' => 'correspondence.index'],
                ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index'],
                ['key' => 'performance', 'label' => 'Performance Monitor', 'icon' => 'check-circle-2', 'route' => 'performance.index'],
            ],
            Role::Officer => [
                ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'home'],
                ['key' => 'officer', 'label' => 'My Dashboard', 'icon' => 'user-circle', 'route' => 'officer.dashboard'],
                ['key' => 'tasks', 'label' => 'My Tasks', 'icon' => 'clipboard-list', 'route' => 'tasks.index'],
                ['key' => 'correspondence', 'label' => 'Correspondence', 'icon' => 'mail', 'route' => 'correspondence.index'],
            ],
        };

        if ($user->can('admin.access') && $user->role !== Role::Sysadmin) {
            array_splice($items, 1, 0, [
                ['key' => 'admin', 'label' => 'Admin Dashboard', 'icon' => 'layout-dashboard', 'route' => 'admin.dashboard'],
                ['key' => 'users', 'label' => 'User Management', 'icon' => 'users', 'route' => 'admin.users.index'],
                ['key' => 'roles', 'label' => 'Roles & Permissions', 'icon' => 'shield-check', 'route' => 'admin.roles.index'],
                ['key' => 'hierarchy', 'label' => 'Organization Hierarchy', 'icon' => 'network', 'route' => 'admin.hierarchy.index'],
                ['key' => 'recipient-aliases', 'label' => 'Recipient Shorthand', 'icon' => 'tags', 'route' => 'admin.recipient-aliases.index'],
            ]);
        }

        if (! config('ats.mail.enabled', true)) {
            $items = array_values(array_filter($items, fn (array $item) => $item['key'] !== 'mail'));
        }

        return array_map(fn (array $item) => [
            'key' => $item['key'],
            'label' => $item['label'],
            'icon' => $item['icon'],
            'tone' => $this->iconTone($item['key']),
            'href' => route($item['route']),
            'active' => $item['key'] === 'mail'
                ? $request->routeIs('mail.*')
                : $request->routeIs($item['route'], $item['route'].'.*'),
        ], $items);
    }

    private function iconTone(string $key): string
    {
        return match ($key) {
            'users', 'officer' => 'cyan',
            'roles', 'settings', 'admin' => 'purple',
            'hierarchy', 'imports' => 'orange',
            'recipient-aliases' => 'pink',
            'departments', 'divisions', 'dept', 'secretary', 'performance' => 'green',
            'mail', 'correspondence', 'exec' => 'amber',
            'audit' => 'slate',
            default => 'blue',
        };
    }
}
