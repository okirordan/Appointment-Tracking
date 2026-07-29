<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Division;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DivisionController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('admin/divisions/index', ['divisions' => Division::with(['department:id,name', 'users:id,division_id'])->withTrashed()->orderBy('name')->get()->map(fn (Division $d) => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code, 'department_id' => $d->department_id, 'department_name' => $d->department->name, 'active' => $d->active && $d->deleted_at === null, 'staff_count' => $d->users->count()]), 'departments' => Department::where('active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $this->validated($request);
        $d = Division::create([...$v, 'active' => true]);
        $this->audit->log('department', 'Created division '.$d->name, $request->user(), 'Division', $d->id, $v);

        return back()->with('success', 'Division created.');
    }

    public function update(Request $request, Division $division): RedirectResponse
    {
        $v = $this->validated($request, $division);
        $before = $division->only('department_id', 'name', 'code');
        $division->update($v);
        $this->audit->log('department', 'Updated division '.$division->name, $request->user(), 'Division', $division->id, ['before' => $before, 'after' => $v]);

        return back()->with('success', 'Division updated.');
    }

    public function toggle(Request $request, Division $division): RedirectResponse
    {
        $division->update(['active' => ! $division->active]);
        $this->audit->log('department', ($division->active ? 'Activated' : 'Deactivated').' division '.$division->name, $request->user(), 'Division', $division->id);

        return back()->with('success', 'Division status updated; historical records were preserved.');
    }

    private function validated(Request $request, ?Division $division = null): array
    {
        return $request->validate(['department_id' => ['required', 'integer', 'exists:departments,id'], 'name' => ['required', 'string', 'max:255', Rule::unique('divisions')->where('department_id', $request->input('department_id'))->ignore($division?->id)->withoutTrashed()], 'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/', Rule::unique('divisions')->where('department_id', $request->input('department_id'))->ignore($division?->id)->withoutTrashed()]]);
    }
}
