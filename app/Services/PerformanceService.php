<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PerformanceService
{
    public function __construct(private TaskPresenter $presenter) {}

    /**
     * Staff performance metrics (PRD §12.17 definitions).
     *
     * @return array<string, mixed>
     */
    public function metricsFor(User $officer): array
    {
        $tasks = Task::where('assigned_to_user_id', $officer->id)->get();

        $assigned = $tasks->count();
        $completed = $tasks->where('workflow_status', TaskStatus::Completed);
        $active = $tasks->filter(fn (Task $task) => ! $task->workflow_status->isClosed());
        $overdue = $tasks->filter(fn (Task $task) => $task->overdue);

        $onTime = $completed->filter(fn (Task $task) => $task->due_date === null
            || ($task->completed_at !== null && $task->completed_at->lte($task->due_date->endOfDay())));

        return [
            'id' => $officer->id,
            'full_name' => $officer->full_name,
            'title' => $officer->title,
            'department_id' => $officer->department_id,
            'department_name' => $officer->department?->name ?? 'Central / Office of the PS',
            'division_id' => $officer->division_id,
            'division_name' => $officer->division?->name ?? 'No division assigned',
            'assigned' => $assigned,
            'completed' => $completed->count(),
            'in_progress' => $active->count(),
            'overdue' => $overdue->count(),
            'average_progress' => $assigned === 0 ? 0 : (int) round($tasks->avg('progress_percent')),
            'on_time_rate' => $completed->count() === 0 ? null : (int) round($onTime->count() / $completed->count() * 100),
        ];
    }

    /**
     * Completion summaries for every officer in the current filtered scope.
     *
     * @param  Collection<int, User>  $officers
     * @return array<string, array{assigned: int, completed: int, completion_rate: int}>
     */
    public function departmentSummaries(Collection $officers): array
    {
        $tasks = Task::query()
            ->whereIn('assigned_to_user_id', $officers->modelKeys())
            ->get(['assigned_to_user_id', 'workflow_status']);

        return $officers
            ->groupBy(fn (User $officer) => $officer->department_id === null ? 'central' : (string) $officer->department_id)
            ->map(function (Collection $departmentOfficers) use ($tasks) {
                $departmentTasks = $tasks->whereIn('assigned_to_user_id', $departmentOfficers->modelKeys());
                $assigned = $departmentTasks->count();
                $completed = $departmentTasks->where('workflow_status', TaskStatus::Completed)->count();

                return [
                    'assigned' => $assigned,
                    'completed' => $completed,
                    'completion_rate' => $assigned === 0 ? 0 : (int) round($completed / $assigned * 100),
                ];
            })
            ->all();
    }

    /**
     * Officers listed for lookup/performance search — current (active)
     * staff only, respecting department scope (LOOK-002, PERF-005).
     *
     * @return Builder<User>
     */
    public function visibleOfficers(User $viewer): Builder
    {
        return $this->scopedOfficers($viewer)->where('active', true);
    }

    /**
     * Authorization decision for viewing a single officer's record.
     *
     * Distinct from {@see visibleOfficers()}: a supervisor may open the
     * historical performance of a *deactivated* officer — deactivating an
     * account never removes their record from oversight (PRD §13.6). The
     * only real restriction is department scope for Commissioners and
     * Secretaries; PS and Sysadmin see everyone.
     */
    public function canViewOfficer(User $viewer, User $officer): bool
    {
        return $this->scopedOfficers($viewer)->whereKey($officer->id)->exists();
    }

    /**
     * Base scope shared by listing and the per-officer access check —
     * department restriction only, no active filter.
     *
     * @return Builder<User>
     */
    private function scopedOfficers(User $viewer): Builder
    {
        $query = User::query()->with(['department:id,name', 'division:id,name,department_id']);

        if (in_array($viewer->role, [Role::Commissioner, Role::Secretary], true)) {
            $query->where('department_id', $viewer->department_id);
        }

        return $query;
    }

    /**
     * Full task portfolio for an officer's drill-down.
     *
     * @return array{tasks: list<array<string, mixed>>, status_distribution: list<array{label: string, count: int, pct: int}>}
     */
    public function portfolio(User $officer): array
    {
        /** @var Collection<int, Task> $tasks */
        $tasks = Task::where('assigned_to_user_id', $officer->id)
            ->with('department')
            ->orderByDesc('created_at')
            ->get();

        $distribution = [];
        foreach (TaskStatus::cases() as $status) {
            if ($status === TaskStatus::Created) {
                continue;
            }
            $count = $tasks->where('workflow_status', $status)->count();
            if ($count > 0) {
                $distribution[] = [
                    'label' => $status->label(),
                    'count' => $count,
                    'pct' => $tasks->isEmpty() ? 0 : (int) round($count / $tasks->count() * 100),
                ];
            }
        }

        return [
            'tasks' => $tasks->map(fn (Task $task) => [
                ...$this->presenter->row($task),
                'assigned_label' => $this->presenter->date($task->created_at),
                'completed_label' => $this->presenter->date($task->completed_at),
            ])->all(),
            'status_distribution' => $distribution,
        ];
    }
}
