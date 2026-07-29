<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('admin/departments/index', [
            'departments' => Department::withTrashed()
                ->orderBy('name')
                ->get()
                ->map(fn (Department $department) => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'head_name' => $department->head_name,
                    'active' => $department->active && $department->deleted_at === null,
                    'officer_count' => $department->activeOfficerCount(),
                    'user_count' => $department->users()->count(),
                    'task_count' => $department->tasks()->count(),
                ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->withoutTrashed()],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/', Rule::unique('departments', 'code')->withoutTrashed()],
            'head_name' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => 'A department with this name already exists.',
            'code.unique' => 'This department code is already in use.',
            'code.regex' => 'Codes use capital letters and digits only (e.g. BSE).',
        ]);

        $department = Department::create([...$validated, 'active' => true]);

        $this->audit->log('department', "Created department {$department->name} ({$department->code})",
            $request->user(), 'Department', $department->id);

        return back()->with('success', "Department {$department->name} created.");
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($department->id)->withoutTrashed()],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/', Rule::unique('departments', 'code')->ignore($department->id)->withoutTrashed()],
            'head_name' => ['nullable', 'string', 'max:255'],
        ]);

        $before = $department->only(['name', 'code', 'head_name']);
        $department->update($validated);

        $this->audit->log('department', "Updated department {$department->name}",
            $request->user(), 'Department', $department->id, ['before' => $before, 'after' => $validated]);

        return back()->with('success', "Department {$department->name} updated.");
    }

    /**
     * DEPT-003/004: removal is deactivation; departments with users or
     * tasks are never hard-deleted, and history keeps its association.
     */
    public function toggleActive(Request $request, Department $department): RedirectResponse
    {
        $department->update(['active' => ! $department->active]);

        $action = $department->active ? 'Reactivated' : 'Deactivated';
        $this->audit->log('department', "{$action} department {$department->name}",
            $request->user(), 'Department', $department->id);

        return back()->with('success', "{$action} {$department->name}.");
    }
}
