<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $roles = Role::query()->with('permissions')->withCount([
            'users as active_users_count' => fn ($query) => $query->where('active', true)->whereNull('deleted_at'),
            'positions as positions_count' => fn ($query) => $query->where('active', true)->whereNull('deleted_at'),
        ])->orderBy('hierarchy_level')->orderBy('display_name')->get();

        return Inertia::render('admin/roles/index', [
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->label(),
                'description' => $role->description,
                'hierarchy_level' => $role->hierarchy_level,
                'is_active' => $role->is_active,
                'is_system' => $role->is_system,
                'active_users_count' => $role->active_users_count,
                'positions_count' => $role->positions_count,
                'permission_names' => $role->permissions->pluck('name')->values(),
            ]),
            'permissionGroups' => Permission::query()->orderBy('group_name')->orderBy('name')->get()
                ->groupBy(fn (Permission $permission) => $permission->group_name ?: 'other')
                ->map(fn ($permissions) => $permissions->map(fn (Permission $permission) => [
                    'name' => $permission->name,
                    'description' => $permission->description,
                ])->values()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $role = Role::create([
            'name' => Str::slug($data['name']),
            'display_name' => $data['name'],
            'guard_name' => 'web',
            'description' => $data['description'] ?? null,
            'hierarchy_level' => $data['hierarchy_level'],
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->syncPermissions($data['permissions'] ?? []);
        $this->audit->log('role', "Created role {$role->label()}", $request->user(), 'Role', $role->id, ['permissions' => $data['permissions'] ?? [], 'hierarchy_level' => $role->hierarchy_level]);

        return back()->with('success', 'Role created. It is immediately available for positions and users.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);
        $before = $role->only(['name', 'display_name', 'description', 'hierarchy_level', 'is_active']);
        $permissionsBefore = $role->permissions()->pluck('name')->all();
        $role->update([
            'name' => $role->is_system ? $role->name : Str::slug($data['name']),
            'display_name' => $data['name'],
            'description' => $data['description'] ?? null,
            'hierarchy_level' => $data['hierarchy_level'],
        ]);
        $role->syncPermissions($data['permissions'] ?? []);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->audit->log('role', "Updated role {$role->label()}", $request->user(), 'Role', $role->id, ['before' => $before, 'after' => $role->fresh()->only(['name', 'display_name', 'description', 'hierarchy_level', 'is_active']), 'permissions_before' => $permissionsBefore, 'permissions_after' => $data['permissions'] ?? [], 'reason' => $data['reason'] ?? null]);

        return back()->with('success', 'Role and permissions updated.');
    }

    public function toggle(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_active && $role->users()->where('active', true)->whereNull('deleted_at')->exists()) {
            return back()->with('error', 'Reassign or deactivate active users before deactivating this role.');
        }
        $role->update(['is_active' => ! $role->is_active]);
        $this->audit->log('role', ($role->is_active ? 'Activated' : 'Deactivated')." role {$role->label()}", $request->user(), 'Role', $role->id);

        return back()->with('success', $role->is_active ? 'Role activated.' : 'Role deactivated.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', 'Built-in compatibility roles cannot be deleted. They may be renamed or deactivated when unused.');
        }
        if ($role->users()->where('active', true)->whereNull('deleted_at')->exists() || $role->positions()->where('active', true)->exists()) {
            return back()->with('error', 'This role is still assigned to active users or positions. Reassign them first.');
        }
        $name = $role->label();
        $id = $role->id;
        $role->delete();
        $this->audit->log('role', "Deleted unused role {$name}", $request->user(), 'Role', $id, ['reason' => $request->string('reason')->toString()]);

        return back()->with('success', 'Unused role deleted.');
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'display_name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'hierarchy_level' => ['required', 'integer', 'min:0', 'max:9999'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
