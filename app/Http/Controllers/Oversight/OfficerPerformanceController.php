<?php

namespace App\Http\Controllers\Oversight;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Division;
use App\Models\User;
use App\Services\PerformanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OfficerPerformanceController extends Controller
{
    public function __construct(private PerformanceService $performance) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $term = $filters['q'];

        $query = $this->performance->visibleOfficers($request->user())
            ->whereIn('role', [Role::Officer->value, Role::Secretary->value, Role::Commissioner->value, Role::Clerk->value])
            ->when($filters['department'] !== '', fn ($query) => $query->where('department_id', (int) $filters['department']))
            ->when($filters['division'] !== '', fn ($query) => $query->where('division_id', (int) $filters['division']));

        if (mb_strlen($term) >= (int) config('ats.search.min_chars')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q
                ->where('full_name', 'like', $like)
                ->orWhere('title', 'like', $like));
        }

        $departmentSummaries = $this->performance->departmentSummaries(
            (clone $query)->get(['id', 'department_id']),
        );

        $rows = $query->orderBy('full_name')
            ->limit(50)
            ->get()
            ->map(fn (User $officer) => $this->performance->metricsFor($officer))
            ->filter(fn (array $metrics) => $metrics['assigned'] > 0)
            ->values()
            ->all();

        return Inertia::render('oversight/officer-performance', [
            'filters' => $filters,
            ...$this->filterOptions($request->user()),
            'rows' => $rows,
            'departmentSummaries' => $departmentSummaries,
            'selected' => null,
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        // View-only access, department-scoped; deactivated officers remain
        // viewable so their historical record stays available (PERF-005).
        abort_unless($this->performance->canViewOfficer($request->user(), $user), 403);

        $filters = $this->filters($request);

        return Inertia::render('oversight/officer-performance', [
            'filters' => $filters,
            ...$this->filterOptions($request->user()),
            'rows' => [],
            'departmentSummaries' => [],
            'selected' => [
                ...$this->performance->metricsFor($user),
                'initials' => $user->initials(),
                ...$this->performance->portfolio($user),
            ],
        ]);
    }

    /** @return array{q: string, department: string, division: string} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'division' => [
                'nullable',
                'integer',
                Rule::exists('divisions', 'id')->where(fn ($query) => $request->filled('department')
                    ? $query->where('department_id', $request->integer('department'))
                    : $query),
            ],
        ]);

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'department' => isset($validated['department']) ? (string) $validated['department'] : '',
            'division' => isset($validated['division']) ? (string) $validated['division'] : '',
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(User $viewer): array
    {
        $departmentOptions = Department::query()
            ->when(
                in_array($viewer->role, [Role::Commissioner, Role::Secretary], true),
                fn ($query) => $query->whereKey($viewer->department_id),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'active']);

        return [
            'departmentOptions' => $departmentOptions,
            'divisionOptions' => Division::query()
                ->when(
                    in_array($viewer->role, [Role::Commissioner, Role::Secretary], true),
                    fn ($query) => $query->where('department_id', $viewer->department_id),
                )
                ->with('department:id,name')
                ->orderBy('name')
                ->get(['id', 'department_id', 'name', 'active'])
                ->map(fn (Division $division) => [
                    'id' => $division->id,
                    'department_id' => $division->department_id,
                    'name' => $division->name,
                    'department_name' => $division->department->name,
                    'active' => $division->active,
                ]),
        ];
    }
}
