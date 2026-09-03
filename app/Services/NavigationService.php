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
        $systemAdministratorOfficerMode = $user->role === Role::Sysadmin
            && $request->session()->get('work_mode', 'administration') === 'officer';

        $items = match ($user->role) {
            Role::Sysadmin => $systemAdministratorOfficerMode ? [
                ['key' => 'search', 'label' => 'Search Mail', 'icon' => 'search', 'route' => 'home', 'parameters' => ['type' => 'mail']],
                ['key' => 'officer', 'label' => 'My Dashboard', 'icon' => 'user-circle', 'route' => 'officer.dashboard'],
                ['key' => 'tasks', 'label' => 'My Tasks', 'icon' => 'clipboard-list', 'route' => 'tasks.index'],
                ['key' => 'correspondence', 'label' => 'Correspondence', 'icon' => 'mail', 'route' => 'correspondence.index'],
            ] : [
                ['key' => 'search', 'label' => 'Search Mail', 'icon' => 'search', 'route' => 'home', 'parameters' => ['type' => 'mail']],
                ['key' => 'admin', 'label' => 'Admin Dashboard', 'icon' => 'layout-dashboard', 'route' => 'admin.dashboard'],
                ['key' => 'users', 'label' => 'User Management', 'icon' => 'users', 'route' => 'admin.users.index'],
                ['key' => 'roles', 'label' => 'Roles & Permissions', 'icon' => 'shield-check', 'route' => 'admin.roles.index'],
                ['key' => 'organization-structure', 'label' => 'Organization Structure', 'icon' => 'network', 'route' => 'admin.organization-structure.index'],
                ['key' => 'recipient-aliases', 'label' => 'Recipient Shorthand', 'icon' => 'tags', 'route' => 'admin.recipient-aliases.index'],
                ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index'],
                ['key' => 'performance', 'label' => 'Performance Monitor', 'icon' => 'check-circle-2', 'route' => 'performance.index'],
                ['key' => 'imports', 'label' => 'Data Imports', 'icon' => 'clipboard-list', 'route' => 'admin.imports.index'],
                ['key' => 'audit', 'label' => 'Audit Log', 'icon' => 'clock', 'route' => 'admin.audit.index'],
                ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'route' => 'admin.settings.index'],
            ],
            Role::Ps => [
                ['key' => 'search', 'label' => 'Search Mail', 'icon' => 'search', 'route' => 'home', 'parameters' => ['type' => 'mail']],
                ['key' => 'mail', 'label' => 'Mails', 'icon' => 'mail', 'route' => 'mail.incoming.index'],
                ['key' => 'filed', 'label' => 'Filed Correspondence', 'icon' => 'archive', 'route' => 'mail.filed.index'],
                ['key' => 'tasks', 'label' => 'All Assignments', 'icon' => 'clipboard-list', 'route' => 'tasks.index'],
                ['key' => 'performance', 'label' => 'Performance Monitor', 'icon' => 'check-circle-2', 'route' => 'performance.index'],
                ['key' => 'correspondence', 'label' => 'Correspondence', 'icon' => 'mail', 'route' => 'correspondence.index'],
                ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index'],
            ],
            Role::Clerk => [
                ['key' => 'search', 'label' => 'Search Mail', 'icon' => 'search', 'route' => 'home', 'parameters' => ['type' => 'mail']],
                ['key' => 'tasks', 'label' => 'Registry Tasks', 'icon' => 'clipboard-list', 'route' => 'tasks.index'],
                ['key' => 'mail', 'label' => 'Mails', 'icon' => 'mail', 'route' => 'mail.incoming.index'],
                ['key' => 'filed', 'label' => 'Filed Correspondence', 'icon' => 'archive', 'route' => 'mail.filed.index'],
                ['key' => 'correspondence', 'label' => 'Correspondence', 'icon' => 'mail', 'route' => 'correspondence.index'],
            ],
            Role::Commissioner => [
                ['key' => 'dept', 'label' => 'Department Work', 'icon' => 'building-2', 'route' => 'dept.dashboard'],
                ['key' => 'mail', 'label' => 'Mails', 'icon' => 'mail', 'route' => 'mail.incoming.index'],
                ['key' => 'search', 'label' => 'Search Mail', 'icon' => 'search', 'route' => 'home', 'parameters' => ['type' => 'mail']],
                ['key' => 'filed', 'label' => 'Filed Correspondence', 'icon' => 'archive', 'route' => 'mail.filed.index'],
                ['key' => 'tasks', 'label' => 'Tasks', 'icon' => 'clipboard-list', 'route' => 'tasks.index'],
                ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index'],
                ['key' => 'performance', 'label' => 'Performance Monitor', 'icon' => 'check-circle-2', 'route' => 'performance.index'],
            ],
            Role::Secretary => [
                [
                    'key' => 'secretary',
                    'label' => 'Department Work',
                    'icon' => 'landmark',
                    'route' => 'secretary.dashboard',
                ],
                ['key' => 'mail', 'label' => 'Mails', 'icon' => 'mail', 'route' => 'mail.incoming.index'],
                ['key' => 'search', 'label' => 'Search Mail', 'icon' => 'search', 'route' => 'home', 'parameters' => ['type' => 'mail']],
                ['key' => 'filed', 'label' => 'Filed Correspondence', 'icon' => 'archive', 'route' => 'mail.filed.index'],
                ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index'],
                ['key' => 'performance', 'label' => 'Performance Monitor', 'icon' => 'check-circle-2', 'route' => 'performance.index'],
            ],
            Role::Officer => [
                ['key' => 'search', 'label' => 'Search Mail', 'icon' => 'search', 'route' => 'home', 'parameters' => ['type' => 'mail']],
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
                ['key' => 'organization-structure', 'label' => 'Organization Structure', 'icon' => 'network', 'route' => 'admin.organization-structure.index'],
                ['key' => 'recipient-aliases', 'label' => 'Recipient Shorthand', 'icon' => 'tags', 'route' => 'admin.recipient-aliases.index'],
            ]);
        }

        if ($user->role !== Role::Sysadmin && $user->can('mail.view') && ! collect($items)->contains('key', 'mail')) {
            array_splice($items, 1, 0, [
                ['key' => 'mail', 'label' => 'Mails', 'icon' => 'mail', 'route' => 'mail.incoming.index'],
                ['key' => 'filed', 'label' => 'Filed Correspondence', 'icon' => 'archive', 'route' => 'mail.filed.index'],
            ]);
        }

        // Filed correspondence is a secondary archive destination and should
        // always remain the final navigation item for roles that can access it.
        $filedItem = collect($items)->firstWhere('key', 'filed');
        if ($filedItem !== null) {
            $items = array_values(array_filter($items, fn (array $item) => $item['key'] !== 'filed'));
            $items[] = $filedItem;
        }

        if (! config('ats.mail.enabled', true)) {
            $items = array_values(array_filter($items, fn (array $item) => ! in_array($item['key'], ['mail', 'filed'], true)));
        }

        return array_map(fn (array $item) => [
            'key' => $item['key'],
            'label' => $item['label'],
            'icon' => $item['icon'],
            'tone' => $this->iconTone($item['key']),
            'href' => route($item['route'], $item['parameters'] ?? []),
            'active' => match ($item['key']) {
                'mail' => $request->routeIs('mail.*') && ! $request->routeIs('mail.filed.index'),
                'filed' => $request->routeIs('mail.filed.index'),
                'dept', 'secretary' => $request->routeIs($item['route'], $item['route'].'.*', 'correspondence.index'),
                default => $request->routeIs($item['route'], $item['route'].'.*'),
            },
        ], $items);
    }

    private function iconTone(string $key): string
    {
        return match ($key) {
            'users', 'officer' => 'cyan',
            'roles', 'settings', 'admin' => 'purple',
            'organization-structure', 'imports' => 'orange',
            'recipient-aliases' => 'pink',
            'departments', 'divisions', 'dept', 'secretary', 'performance' => 'green',
            'mail', 'correspondence', 'exec' => 'amber',
            'audit', 'filed' => 'slate',
            default => 'blue',
        };
    }
}
