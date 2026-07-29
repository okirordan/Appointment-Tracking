<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\AssignmentLevel;
use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Models\Department;
use App\Models\Task;
use App\Models\Workstream;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskScope;
use App\Services\Tasks\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function __construct(
        private TaskScope $scope,
        private TaskService $service,
        private TaskPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, Task $task): Response
    {
        $this->authorize('view', $task);

        return $this->render($request, $task);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $task = $this->service->create($request->user(), $request->validated());

        $label = $task->assignment_level === AssignmentLevel::Ps ? 'Assignment' : 'Task';

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', "{$label} {$task->reference} created.");
    }

    private function render(Request $request, ?Task $selected): Response
    {
        $user = $request->user();

        // TASK-009: filter selections persist for the session so returning
        // from a task restores the same list.
        $filters = [
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'priority' => (string) $request->query('priority', ''),
            'department' => (string) $request->query('department', ''),
            'workstream' => (string) $request->query('workstream', ''),
        ];
        if (! $request->hasAny(['q', 'status', 'priority', 'department', 'workstream', 'page'])) {
            // Older sessions may predate newly introduced filters. Merge the
            // saved values over today's complete shape so every key exists.
            $filters = array_replace($filters, $request->session()->get('tasks.filters', []));
        } else {
            $request->session()->put('tasks.filters', $filters);
        }

        $query = $this->scope->query($user)
            ->with('department')
            ->orderByDesc('updated_at');

        $scopedTotal = (clone $query)->count();

        if ($filters['q'] !== '') {
            $query->matchingKeywords($filters['q'], ['reference', 'assigned_to_name_snapshot']);
        }

        if ($filters['status'] === 'overdue') {
            $query->overdue();
        } elseif ($filters['status'] !== '') {
            $query->where('workflow_status', $filters['status']);
        }

        if ($filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
        }

        if ($filters['workstream'] !== '') {
            $query->where('workstream_id', (int) $filters['workstream']);
        }

        $showDeptFilter = in_array($user->role, [Role::Sysadmin, Role::Ps, Role::Clerk], true);
        if ($showDeptFilter && $filters['department'] !== '') {
            $query->where('department_id', (int) $filters['department']);
        }

        $tasks = $query->paginate(15)->withQueryString();

        $pageTitle = match ($user->role) {
            Role::Ps => 'All Assignments',
            Role::Clerk => 'PS Office Assignments',
            Role::Commissioner => 'Department Assignments',
            Role::Secretary => $user->currentSecretaryAttachment()->exists() ? 'Supported Office Assignments' : 'Department Assignments',
            Role::Officer => 'My Tasks',
            Role::Sysadmin => 'All Assignments',
        };

        return Inertia::render('tasks/index', [
            'pageTitle' => $pageTitle,
            'newTaskLabel' => in_array($user->role, [Role::Ps, Role::Clerk], true) ? 'Assignment' : 'Task',
            'canCreate' => $user->can('create', Task::class),
            'filters' => $filters,
            'showDeptFilter' => $showDeptFilter,
            'scopedTotal' => $scopedTotal,
            'tasks' => [
                'data' => collect($tasks->items())->map(fn (Task $task) => $this->presenter->row($task))->all(),
                'meta' => [
                    'current_page' => $tasks->currentPage(),
                    'last_page' => $tasks->lastPage(),
                    'total' => $tasks->total(),
                ],
            ],
            'statusOptions' => collect(TaskStatus::cases())
                ->reject(fn (TaskStatus $status) => $status === TaskStatus::Created)
                ->map(fn (TaskStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                ->values()->all(),
            'priorityOptions' => collect(Priority::cases())
                ->map(fn (Priority $priority) => ['value' => $priority->value, 'label' => $priority->label()])
                ->all(),
            'workstreamOptions' => Workstream::where('active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'createdWorkstreamId' => $request->session()->get('created_workstream_id'),
            'departmentOptions' => $showDeptFilter
                ? Department::where('active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'updateStatusOptions' => collect(TaskStatus::selectableForUpdate())
                ->map(fn (TaskStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'suggested_progress' => $status->suggestedProgress(),
                ])->values()->all(),
            'selectedTask' => $selected === null ? null : [
                ...$this->presenter->detail($selected, $user),
                'can_update_progress' => $request->user()->can('updateProgress', $selected),
                'can_annotate' => $request->user()->can('annotate', $selected),
                'can_delegate' => $request->user()->can('delegate', $selected),
                'can_submit' => $request->user()->can('submit', $selected),
                'can_review' => $request->user()->can('review', $selected),
                'can_reassign' => $request->user()->can('reassign', $selected),
                'can_direct' => $request->user()->can('assignments.direct') || in_array($request->user()->role, [Role::Sysadmin, Role::Ps, Role::Commissioner], true),
            ],
        ]);
    }
}
