<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Role;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use App\Models\UserDelegation;
use App\Models\UserPosition;
use App\Models\UserProfileChange;
use App\Services\AuditLogger;
use App\Services\SecretaryAttachmentService;
use App\Services\SecretaryAuthorityService;
use App\Services\UserPositionService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class HierarchyController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private UserPositionService $userPositions,
        private SecretaryAttachmentService $secretaryAttachments,
        private SecretaryAuthorityService $secretaryAuthority,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/hierarchy/index', [
            'units' => OrganizationalUnit::with(['parent:id,name', 'department:id,name', 'division:id,name'])->withCount(['positions', 'users'])->orderBy('type')->orderBy('name')->get()->map(fn ($unit) => [
                'id' => $unit->id, 'name' => $unit->name, 'code' => $unit->code, 'type' => $unit->type,
                'parent_id' => $unit->parent_id, 'parent_name' => $unit->parent?->name,
                'department_id' => $unit->department_id, 'department_name' => $unit->department?->name,
                'division_id' => $unit->division_id, 'division_name' => $unit->division?->name,
                'active' => $unit->active, 'positions_count' => $unit->positions_count, 'users_count' => $unit->users_count,
            ]),
            'departments' => Department::where('active', true)->orderBy('name')->get(['id', 'name']),
            'divisions' => Division::where('active', true)->orderBy('name')->get(['id', 'name', 'department_id']),
            'positions' => Position::with(['organizationalUnit:id,name', 'role:id,name,display_name', 'supervisorPosition:id,title'])->withCount(['appointments as active_users_count' => fn ($query) => $query->current()])->orderBy('hierarchy_level')->get()->map(fn ($position) => [
                'id' => $position->id, 'title' => $position->title, 'hierarchy_level' => $position->hierarchy_level,
                'organizational_unit_id' => $position->organizational_unit_id, 'unit_name' => $position->organizationalUnit?->name ?? 'Institution-wide',
                'role_id' => $position->role_id, 'role_name' => $position->role?->label(), 'supervisor_position_id' => $position->supervisor_position_id,
                'supervisor_position_name' => $position->supervisorPosition?->title, 'capabilities' => $position->workflow_capabilities ?? [], 'active' => $position->active,
                'active_users_count' => $position->active_users_count,
            ]),
            'appointments' => UserPosition::current()->with(['user' => fn ($q) => $q->withTrashed(), 'position.organizationalUnit', 'supervisor' => fn ($q) => $q->withTrashed()])->get()->map(fn ($item) => [
                'id' => $item->id, 'user_id' => $item->user_id, 'user_name' => $item->user?->full_name, 'user_inactive' => ! ($item->user?->active ?? false) || $item->user?->trashed(),
                'position_id' => $item->position_id, 'position_name' => $item->position?->title, 'unit_name' => $item->position?->organizationalUnit?->name,
                'supervisor_user_id' => $item->supervisor_user_id, 'supervisor_name' => $item->supervisor?->full_name, 'is_acting' => $item->is_acting,
                'starts_at' => $item->starts_at?->format('Y-m-d\TH:i'), 'ends_at' => $item->ends_at?->format('Y-m-d\TH:i'),
            ]),
            'delegations' => UserDelegation::current()->with(['delegator', 'delegate', 'organizationalUnit'])->get()->map(fn ($item) => [
                'id' => $item->id, 'delegator_name' => $item->delegator?->full_name, 'delegate_name' => $item->delegate?->full_name,
                'unit_name' => $item->organizationalUnit?->name ?? 'All authorized work', 'starts_at' => $item->starts_at->format('d/m/Y H:i'), 'ends_at' => $item->ends_at->format('d/m/Y H:i'), 'reason' => $item->reason,
            ]),
            'secretaryAttachments' => SecretaryOfficeAttachment::current()
                ->with(['secretary', 'supervisor', 'organizationalUnit'])
                ->orderByDesc('starts_at')
                ->get()
                ->map(fn (SecretaryOfficeAttachment $item) => [
                    'id' => $item->id,
                    'secretary_name' => $item->secretary?->full_name,
                    'official_job_title' => $item->official_job_title,
                    'supervisor_name' => $item->supervisor?->full_name,
                    'supervisor_title' => $item->supervisor?->title,
                    'office_name' => $item->organizationalUnit?->name ?? $item->supervisor?->title,
                    'starts_at' => $item->starts_at->format('d/m/Y'),
                    'ends_at' => $item->ends_at?->format('d/m/Y'),
                    'delegated_permissions' => collect($item->delegated_permissions ?? [])
                        ->map(fn (string $permission) => $this->secretaryAuthority->availablePermissions()[$permission] ?? $permission)
                        ->values(),
                ]),
            'secretaryPermissionOptions' => collect($this->secretaryAuthority->availablePermissions())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'roles' => Role::where('is_active', true)->orderBy('hierarchy_level')->get()->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->label(), 'hierarchy_level' => $role->hierarchy_level]),
            'users' => User::where('active', true)
                ->with('department:id,name')
                ->withCount([
                    'assignmentParticipations as active_task_count' => fn ($query) => $query
                        ->where('active', true)
                        ->whereHas('task', fn ($task) => $task->whereNotIn('workflow_status', ['completed', 'archived'])),
                ])
                ->orderBy('full_name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'title' => $user->title,
                    'department_name' => $user->department?->name,
                    'active_task_count' => $user->active_task_count,
                ]),
        ]);
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        [$data, $reason] = $this->unitData($request);
        $unit = OrganizationalUnit::create([...$data, 'active' => true]);
        $this->audit->log('hierarchy', "Created organizational unit {$unit->name}", $request->user(), 'OrganizationalUnit', $unit->id, [...$data, 'reason' => $reason]);

        return back()->with('success', 'Organizational unit added.');
    }

    public function updateUnit(Request $request, OrganizationalUnit $unit): RedirectResponse
    {
        [$data, $reason] = $this->unitData($request, $unit);
        $before = $unit->toArray();
        $updatedUsers = DB::transaction(function () use ($unit, $data, $request, $reason): int {
            $unit->update($data);
            if ($unit->department_id === null) {
                return 0;
            }

            $users = User::query()->where('organizational_unit_id', $unit->id)->lockForUpdate()->get();
            foreach ($users as $user) {
                foreach (['department_id' => $unit->department_id, 'division_id' => $unit->division_id] as $field => $newValue) {
                    $oldValue = $user->{$field};
                    if ((string) $oldValue === (string) $newValue) {
                        continue;
                    }
                    $user->forceFill([$field => $newValue])->save();
                    UserProfileChange::create([
                        'user_id' => $user->id,
                        'field_name' => $field,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'changed_by_user_id' => $request->user()->id,
                        'reason' => $reason ?: "Organizational unit {$unit->name} placement updated.",
                        'created_at' => now(),
                    ]);
                }
            }

            return $users->count();
        });
        $this->audit->log('hierarchy', "Updated organizational unit {$unit->name}", $request->user(), 'OrganizationalUnit', $unit->id, [
            'before' => $before,
            'after' => $unit->fresh()->toArray(),
            'affected_users' => $updatedUsers,
            'reason' => $reason,
        ]);

        return back()->with('success', 'Organizational unit updated.');
    }

    public function storePosition(Request $request): RedirectResponse
    {
        $data = $this->positionData($request);
        $position = Position::create([...$data, 'active' => true]);
        $this->audit->log('hierarchy', "Created position {$position->title}", $request->user(), 'Position', $position->id, $data);

        return back()->with('success', 'Position added to the hierarchy.');
    }

    public function updatePosition(Request $request, Position $position): RedirectResponse
    {
        $data = $this->positionData($request, $position);
        $before = $position->toArray();
        $position->update($data);
        $this->audit->log('hierarchy', "Updated position {$position->title}", $request->user(), 'Position', $position->id, ['before' => $before, 'after' => $position->fresh()->toArray()]);

        return back()->with('success', 'Position updated.');
    }

    public function assignUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true)],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->whereNull('deleted_at')->where('active', true)],
            'supervisor_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true), 'different:user_id'],
            'is_acting' => ['sometimes', 'boolean'], 'acting_for_user_id' => ['nullable', 'integer', 'exists:users,id', 'different:user_id'],
            'starts_at' => ['nullable', 'date', 'before_or_equal:now'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $position = Position::with(['role', 'organizationalUnit'])->findOrFail($data['position_id']);
        $user = User::findOrFail($data['user_id']);
        $appointment = $this->userPositions->change($user, $position, $request->user(), [
            'supervisor_user_id' => $data['supervisor_user_id'] ?? null,
            'is_acting' => (bool) ($data['is_acting'] ?? false),
            'acting_for_user_id' => $data['acting_for_user_id'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'effective_date' => $data['starts_at'] ?? now(),
            'reason' => $data['reason'] ?? null,
        ]);

        return back()->with('success', 'User dashboard, permissions, title and reporting line updated.');
    }

    public function storeDelegation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'delegator_user_id' => ['required', 'integer', 'exists:users,id'], 'delegate_user_id' => ['required', 'integer', 'exists:users,id', 'different:delegator_user_id'],
            'organizational_unit_id' => ['nullable', 'integer', 'exists:organizational_units,id'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $delegation = UserDelegation::create([...$data, 'active' => true, 'created_by_user_id' => $request->user()->id]);
        $this->audit->log('hierarchy', 'Created temporary delegation arrangement', $request->user(), 'UserDelegation', $delegation->id, $data);

        return back()->with('success', 'Temporary delegation is active for the selected period.');
    }

    public function assignSecretary(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'secretary_user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true)],
            'supervisor_user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true), 'different:secretary_user_id'],
            'organizational_unit_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')->whereNull('deleted_at')->where('active', true)],
            'official_job_title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'before_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'delegated_actions_permitted' => ['sometimes', 'boolean'],
            'delegated_permissions' => ['nullable', 'array'],
            'delegated_permissions.*' => ['string', Rule::in(array_keys($this->secretaryAuthority->availablePermissions()))],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->secretaryAttachments->assign(
            User::findOrFail($data['secretary_user_id']),
            User::findOrFail($data['supervisor_user_id']),
            empty($data['organizational_unit_id']) ? null : OrganizationalUnit::findOrFail($data['organizational_unit_id']),
            $data['official_job_title'],
            Carbon::parse($data['starts_at']),
            empty($data['ends_at']) ? null : Carbon::parse($data['ends_at']),
            (bool) ($data['delegated_actions_permitted'] ?? false),
            $data['delegated_permissions'] ?? [],
            $request->user(),
            $data['reason'],
        );

        return back()->with('success', 'Secretary office attachment, dashboard scope and delegated authority updated.');
    }

    public function endSecretaryAttachment(Request $request, SecretaryOfficeAttachment $attachment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $this->secretaryAttachments->end($attachment, $request->user(), $data['reason']);

        return back()->with('success', 'Secretary office access removed. Existing correspondence and assignment records were preserved.');
    }

    private function positionData(Request $request, ?Position $position = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'], 'organizational_unit_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true)],
            'supervisor_position_id' => ['nullable', 'integer', Rule::exists('positions', 'id'), Rule::notIn(array_filter([$position?->id]))],
            'hierarchy_level' => ['required', 'integer', 'min:0', 'max:9999'], 'workflow_capabilities' => ['nullable', 'array'],
            'workflow_capabilities.*' => [Rule::in(['assign', 'review', 'approve', 'reject', 'return', 'escalate'])], 'active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: ?string} */
    private function unitData(Request $request, ?OrganizationalUnit $unit = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('organizational_units', 'code')->ignore($unit)],
            'type' => ['required', Rule::in(['institution', 'directorate', 'department', 'division', 'section', 'unit'])],
            'parent_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id'), Rule::notIn(array_filter([$unit?->id]))],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('active', true)],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')->where('active', true)->whereNull('deleted_at')],
            'active' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = $data['reason'] ?? null;
        unset($data['reason']);
        $parent = filled($data['parent_id'] ?? null) ? OrganizationalUnit::findOrFail($data['parent_id']) : null;
        $data['department_id'] = $data['department_id'] ?? $parent?->department_id;
        $data['division_id'] = $data['division_id'] ?? $parent?->division_id;

        if ($data['division_id'] !== null) {
            $division = Division::findOrFail($data['division_id']);
            if ($data['department_id'] === null || $division->department_id !== (int) $data['department_id']) {
                throw ValidationException::withMessages([
                    'division_id' => 'Select a division belonging to the selected department.',
                ]);
            }
        }

        return [$data, $reason];
    }
}
