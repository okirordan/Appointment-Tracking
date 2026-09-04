<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role as PermissionRole;
use App\Models\Task;
use App\Models\User;
use App\Models\UserLifecycleEvent;
use App\Models\UserPosition;
use App\Models\UserPositionChange;
use App\Models\UserProfileChange;
use App\Services\AuditLogger;
use App\Services\StaffOrganizationalPlacementService;
use App\Services\Tasks\AssignmentWorkflowService;
use App\Services\UserPositionService;
use App\Support\TemporaryPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private AssignmentWorkflowService $workflow,
        private UserPositionService $positions,
        private StaffOrganizationalPlacementService $placement,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $organizationOptions = $this->placement->options();
        $organizationPaths = collect($organizationOptions)->keyBy('id');

        $users = User::withTrashed()
            ->with(['organizationalUnit:id,name', 'roles:id,name,display_name,is_active', 'supervisor' => fn ($query) => $query->withTrashed()->select('id', 'full_name')])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(fn ($q) => $q
                    ->where('full_name', 'like', $like)
                    ->orWhere('username', 'like', $like));
            })
            ->orderBy('full_name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/users/index', [
            'search' => $search,
            'users' => [
                'data' => collect($users->items())->map(fn (User $user) => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'title' => $user->title,
                    'username' => $user->username,
                    'role_id' => $user->permissionRole()?->id,
                    'role_name' => $user->roleName(),
                    'role_label' => $user->roleLabel(),
                    'organization_path' => $organizationPaths->get($user->organizational_unit_id)['path'] ?? $user->organizationalUnit?->name ?? '—',
                    'active' => $user->active,
                    'deleted' => $user->trashed(),
                    'deleted_at_label' => $user->deleted_at?->format('d/m/Y H:i'),
                    'supervisor_name' => $user->supervisor?->full_name,
                    'locked' => $user->locked,
                    'force_password_change' => $user->force_password_change,
                    'failed_login_count' => $user->failed_login_count,
                    'last_login_label' => $user->last_login_at?->format('d/m/Y H:i') ?? '—',
                    'password_changed_label' => $user->password_changed_at?->format('d/m/Y H:i') ?? '—',
                    'password_reset_label' => $user->password_reset_at?->format('d/m/Y H:i') ?? '—',
                ])->all(),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'total' => $users->total(),
                ],
            ],
            'roleOptions' => PermissionRole::where('is_active', true)->orderBy('hierarchy_level')->orderBy('display_name')->get()
                ->map(fn (PermissionRole $role) => ['value' => (string) $role->id, 'label' => $role->label(), 'name' => $role->name]),
            'organizationOptions' => $organizationOptions,
            'positionOptions' => Position::where('active', true)->orderBy('hierarchy_level')->orderBy('title')->get(['id', 'title', 'organizational_unit_id', 'role_id']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        // USR-002/003 & PWD-008: new accounts start with a unique temporary
        // password and must change it at first login.
        $temporaryPassword = TemporaryPassword::generate();

        $data = $request->validated();
        $positionId = $data['position_id'] ?? null;
        $organizationalUnitId = $data['organizational_unit_id'] ?? null;
        unset($data['position_id'], $data['organizational_unit_id']);
        $position = $positionId === null ? null : Position::with(['role', 'organizationalUnit'])->findOrFail($positionId);
        $organizationalUnit = $position?->organizationalUnit
            ?? ($organizationalUnitId === null ? null : OrganizationalUnit::findOrFail($organizationalUnitId));
        if ($position !== null) {
            $data['title'] = $position->title;
        }
        $data['department_id'] = $organizationalUnit?->department_id;
        $data['division_id'] = $organizationalUnit?->division_id;
        $data['organizational_unit_id'] = $organizationalUnit?->id;
        $permissionRole = $position?->role ?? (isset($data['role_id']) ? PermissionRole::findOrFail($data['role_id']) : null);
        $legacyRole = $permissionRole === null ? Role::from($data['role']) : (Role::tryFrom($permissionRole->name) ?? Role::Officer);
        $this->placement->assertAssignable($permissionRole ?? $legacyRole->value, $organizationalUnit);
        unset($data['role_id']);
        $data['role'] = $legacyRole->value;

        $user = DB::transaction(function () use ($data, $temporaryPassword, $permissionRole, $position) {
            $user = User::create([
                ...$data,
                'password' => $temporaryPassword,
                'force_password_change' => true,
                'active' => true,
            ]);

            if ($permissionRole !== null) {
                $user->syncRoles([$permissionRole]);
            }

            if ($position !== null) {
                UserPosition::create([
                    'user_id' => $user->id,
                    'position_id' => $position->id,
                    'is_primary' => true,
                    'is_acting' => false,
                    'starts_at' => now(),
                    'active' => true,
                ]);
            }

            return $user;
        });

        $this->audit->log('user', "Created user account {$user->username}", $request->user(), 'User', $user->id, [
            'role' => $user->roleName(),
            'department_id' => $user->department_id,
            'organizational_unit_id' => $organizationalUnit?->id,
            'position_id' => $position?->id,
        ]);

        // Temporary password shown once in a copyable dialog (see reset()).
        return redirect()->route('admin.users.index')
            ->with('success', "Account created for {$user->full_name}.")
            ->with('temp_credential', [
                'name' => $user->full_name,
                'username' => $user->username,
                'password' => $temporaryPassword,
                'context' => 'created',
            ]);
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        // USR-005: the primary administrator account is protected.
        $primaryAdmin = User::where('role', Role::Sysadmin->value)->orderBy('id')->first();
        if ($primaryAdmin !== null && $user->id === $primaryAdmin->id) {
            return back()->with('error', 'The primary administrator account is protected.');
        }

        $user->update(['active' => ! $user->active]);

        $action = $user->active ? 'Activated' : 'Deactivated';
        $this->audit->log('user', "{$action} user account {$user->username}", $request->user(), 'User', $user->id);

        return back()->with('success', "{$action} {$user->full_name}.");
    }

    public function show(int $user): Response
    {
        $model = User::withTrashed()->with([
            'organizationalUnit:id,name', 'roles:id,name,display_name,is_active',
            'supervisor' => fn ($query) => $query->withTrashed(),
            'profileChanges.changedBy' => fn ($query) => $query->withTrashed(),
            'positionChanges.changedBy' => fn ($query) => $query->withTrashed(),
            'positionChanges.previousPosition', 'positionChanges.newPosition',
            'positionChanges.previousRole', 'positionChanges.newRole',
            'lifecycleEvents.performedBy' => fn ($query) => $query->withTrashed(),
            'currentPositionAssignment.position.organizationalUnit',
            'currentSecretaryAttachment.supervisor', 'currentSecretaryAttachment.organizationalUnit',
        ])->findOrFail($user);

        $organizationOptions = $this->placement->options();
        $effectiveUnitId = $model->currentPositionAssignment?->position?->organizational_unit_id ?? $model->organizational_unit_id;
        $organizationPath = collect($organizationOptions)->firstWhere('id', $effectiveUnitId)['path'] ?? null;

        return Inertia::render('admin/users/show', [
            'userRecord' => [
                'id' => $model->id, 'full_name' => $model->full_name, 'title' => $model->title, 'username' => $model->username,
                'email' => $model->email, 'employee_number' => $model->employee_number, 'role_id' => $model->permissionRole()?->id,
                'role_label' => $model->roleLabel(), 'supervisor_user_id' => $model->supervisor_user_id,
                'supervisor_name' => $model->supervisor?->full_name, 'position_name' => $model->currentPositionAssignment?->position?->title,
                'position_id' => $model->currentPositionAssignment?->position_id,
                'organizational_unit_id' => $effectiveUnitId,
                'organization_path' => $organizationPath ?? $model->currentPositionAssignment?->position?->organizationalUnit?->name ?? $model->organizationalUnit?->name,
                'active' => $model->active,
                'supported_office_name' => $model->currentSecretaryAttachment?->organizationalUnit?->name,
                'supported_supervisor_name' => $model->currentSecretaryAttachment?->supervisor?->full_name,
                'deleted' => $model->trashed(), 'deletion_reason' => $model->deletion_reason, 'deleted_at_label' => $model->deleted_at?->format('d/m/Y H:i'),
            ],
            'changes' => $model->profileChanges->map(fn (UserProfileChange $change) => [
                'id' => $change->id, 'field' => str($change->field_name)->replace('_', ' ')->title(), 'old_value' => $change->old_value,
                'new_value' => $change->new_value, 'changed_by' => $change->changedBy?->full_name ?? 'System', 'reason' => $change->reason,
                'when_label' => $change->created_at->format('d/m/Y H:i'),
            ]),
            'positionChanges' => $model->positionChanges->map(fn (UserPositionChange $change) => [
                'id' => $change->id,
                'previous_title' => $change->previous_title,
                'new_title' => $change->new_title,
                'previous_role' => $change->previousRole?->label(),
                'new_role' => $change->newRole?->label(),
                'previous_position' => $change->previousPosition?->title,
                'new_position' => $change->newPosition?->title,
                'effective_date_label' => $change->effective_date->format('d/m/Y'),
                'changed_by' => $change->changedBy?->full_name ?? 'System',
                'changed_at_label' => $change->changed_at->format('d/m/Y H:i'),
                'reason' => $change->reason,
            ]),
            'lifecycle' => $model->lifecycleEvents->map(fn (UserLifecycleEvent $event) => [
                'id' => $event->id, 'event' => str($event->event_type)->replace('_', ' ')->title(), 'performed_by' => $event->performedBy?->full_name ?? 'System',
                'reason' => $event->reason, 'when_label' => $event->created_at->format('d/m/Y H:i'),
            ]),
            'roleOptions' => PermissionRole::where('is_active', true)->orderBy('hierarchy_level')->get()->map(fn (PermissionRole $role) => ['value' => (string) $role->id, 'label' => $role->label(), 'name' => $role->name]),
            'organizationOptions' => $organizationOptions,
            'positionOptions' => Position::where('active', true)->orderBy('hierarchy_level')->orderBy('title')->get(['id', 'title', 'organizational_unit_id', 'role_id']),
            'userOptions' => User::where('active', true)->whereKeyNot($model->id)->orderBy('full_name')->get(['id', 'full_name', 'title']),
            'today' => now()->toDateString(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if ($request->has('username')) {
            $request->merge([
                'username' => mb_strtolower(trim((string) $request->input('username'))),
            ]);
        }

        $data = $request->validate([
            'username' => [
                'sometimes', 'required', 'string', 'max:60',
                'regex:/^[a-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($user),
            ],
            'full_name' => ['required', 'string', 'max:255'], 'title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'employee_number' => ['nullable', 'string', 'max:80', Rule::unique('users', 'employee_number')->ignore($user)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'], 'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'organizational_unit_id' => [
                'nullable', 'integer',
                Rule::exists('organizational_units', 'id')
                    ->where('active', true)
                    ->whereNull('deleted_at')
                    ->whereIn('type', StaffOrganizationalPlacementService::assignableTypeValues()),
            ],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('active', true)->whereNull('deleted_at')],
            'supervisor_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true), 'different:'.$user->id],
            'effective_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ], [
            'username.unique' => 'This username is already used by another account.',
            'username.regex' => 'Usernames may only contain lowercase letters, numbers, dots, hyphens, and underscores.',
        ]);
        $role = PermissionRole::findOrFail($data['role_id']);
        $position = filled($data['position_id'] ?? null)
            ? Position::with(['role', 'organizationalUnit'])->findOrFail($data['position_id'])
            : null;
        if ($position !== null && (int) $position->organizational_unit_id !== (int) ($data['organizational_unit_id'] ?? 0)) {
            throw ValidationException::withMessages(['position_id' => 'Select a position belonging to the selected organizational unit.']);
        }
        if ($position !== null && $position->role_id !== $role->id) {
            throw ValidationException::withMessages(['role_id' => 'The system role must match the selected position’s configured role.']);
        }
        $organizationalUnit = $position?->organizationalUnit
            ?? (filled($data['organizational_unit_id'] ?? null) ? OrganizationalUnit::findOrFail($data['organizational_unit_id']) : null);
        $this->placement->assertAssignable($role, $organizationalUnit);
        if ($position === null) {
            $data['organizational_unit_id'] = $organizationalUnit?->id;
            $data['department_id'] = $organizationalUnit?->department_id;
            $data['division_id'] = $organizationalUnit?->division_id;
        }
        $reason = $data['reason'] ?? null;
        $effectiveDate = $data['effective_date'] ?? now()->toDateString();
        unset($data['role_id'], $data['reason'], $data['effective_date'], $data['position_id']);
        // Login identifiers are matched case-insensitively (AUTH-010).
        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }
        if (array_key_exists('employee_number', $data) && $data['employee_number'] !== null) {
            $data['employee_number'] = trim($data['employee_number']);
        }
        $placementFields = ['title', 'department_id', 'division_id', 'organizational_unit_id', 'supervisor_user_id'];
        $profileData = $position === null ? $data : collect($data)->except($placementFields)->all();
        $legacy = Role::tryFrom($role->name);
        if ($position === null && $legacy !== null) {
            $profileData['role'] = $legacy->value;
        }

        DB::transaction(function () use ($user, $profileData, $role, $position, $organizationalUnit, $effectiveDate, $reason, $request, $data) {
            $before = $user->only(array_keys($profileData));
            $oldRole = $user->roleName();
            $oldRoleId = $user->permissionRole()?->id;
            $oldTitle = $user->title;
            $currentAssignment = $user->currentPositionAssignment()->lockForUpdate()->first();
            $currentPositionId = $currentAssignment?->position_id;
            $endedApprovedPosition = $position === null && $currentAssignment !== null;
            if ($endedApprovedPosition) {
                $currentAssignment->update([
                    'active' => false,
                    'ends_at' => $effectiveDate,
                ]);
            }
            $user->update($profileData);
            if ($position === null) {
                $user->syncRoles([$role]);
            }
            foreach ($profileData as $field => $newValue) {
                $oldValue = $before[$field] ?? null;
                $oldValue = $oldValue instanceof \BackedEnum ? $oldValue->value : $oldValue;
                $newValue = $newValue instanceof \BackedEnum ? $newValue->value : $newValue;
                if ((string) $oldValue === (string) $newValue) {
                    continue;
                }
                UserProfileChange::create(['user_id' => $user->id, 'field_name' => $field, 'old_value' => $oldValue, 'new_value' => $newValue, 'changed_by_user_id' => $request->user()->id, 'reason' => $reason, 'created_at' => now()]);
            }
            if ($position === null && $oldRole !== $role->name) {
                UserProfileChange::create(['user_id' => $user->id, 'field_name' => 'role', 'old_value' => $oldRole, 'new_value' => $role->name, 'changed_by_user_id' => $request->user()->id, 'reason' => $reason, 'created_at' => now()]);
            }
            if ($position !== null) {
                $this->positions->change($user, $position, $request->user(), [
                    'supervisor_user_id' => $data['supervisor_user_id'] ?? null,
                    'effective_date' => $effectiveDate,
                    'reason' => $reason,
                ]);
            } elseif ($endedApprovedPosition || $oldTitle !== $user->fresh()->title || $oldRole !== $role->name) {
                UserPositionChange::create([
                    'user_id' => $user->id,
                    'previous_position_id' => $currentPositionId,
                    'new_position_id' => $endedApprovedPosition ? null : $currentPositionId,
                    'previous_role_id' => $oldRoleId,
                    'new_role_id' => $role->id,
                    'previous_title' => $oldTitle,
                    'new_title' => $user->title,
                    'effective_date' => $effectiveDate,
                    'changed_at' => now(),
                    'changed_by_user_id' => $request->user()->id,
                    'reason' => $reason,
                ]);
            }
            $this->placement->synchronizeSecretaryAttachment($user, $role, $organizationalUnit, $request->user());
        });
        $this->audit->log('user', "Updated profile for {$user->username}", $request->user(), 'User', $user->id, [
            'changed_fields' => array_keys($profileData),
            'organizational_unit_id' => $organizationalUnit?->id,
            'reason' => $reason,
        ]);

        return back()->with('success', 'User profile updated and change history recorded.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'replacement_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true), 'different:'.$user->id]]);
        $openTasks = Task::query()->active()->where(fn ($query) => $query->where('current_assignee_user_id', $user->id)->orWhere(fn ($legacy) => $legacy->whereNull('current_assignee_user_id')->where('assigned_to_user_id', $user->id)))->get();
        if ($openTasks->isNotEmpty() && empty($data['replacement_user_id'])) {
            return back()->withErrors(['replacement_user_id' => "Choose a replacement for {$openTasks->count()} open assignment(s) before deleting this user."]);
        }
        if (! empty($data['replacement_user_id'])) {
            $replacement = User::findOrFail($data['replacement_user_id']);
            foreach ($openTasks as $task) {
                $this->workflow->reassign($request->user(), $task, $replacement, 'Reassignment during user deletion: '.$data['reason']);
            }
        }
        $user->forceFill(['active' => false, 'deleted_by_user_id' => $request->user()->id, 'deletion_reason' => $data['reason']])->save();
        $user->delete();
        UserLifecycleEvent::create(['user_id' => $user->id, 'event_type' => 'soft_deleted', 'performed_by_user_id' => $request->user()->id, 'reason' => $data['reason'], 'metadata' => ['reassigned_tasks' => $openTasks->count(), 'replacement_user_id' => $data['replacement_user_id'] ?? null], 'created_at' => now()]);
        $this->audit->log('user', "Soft-deleted user {$user->username}", $request->user(), 'User', $user->id, ['reason' => $data['reason'], 'reassigned_tasks' => $openTasks->count()]);

        return redirect()->route('admin.users.index')->with('success', 'User deleted safely. Historical records remain available.');
    }

    public function restore(Request $request, int $user): RedirectResponse
    {
        $model = User::withTrashed()->findOrFail($user);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $model->restore();
        $model->forceFill(['active' => true, 'restored_by_user_id' => $request->user()->id, 'deletion_reason' => null])->save();
        UserLifecycleEvent::create(['user_id' => $model->id, 'event_type' => 'restored', 'performed_by_user_id' => $request->user()->id, 'reason' => $data['reason'], 'created_at' => now()]);
        $this->audit->log('user', "Restored user {$model->username}", $request->user(), 'User', $model->id, ['reason' => $data['reason']]);

        return redirect()->route('admin.users.show', $model)->with('success', 'User restored and may sign in again.');
    }
}
