<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\User;
use App\Services\Mail\MailAccessScope;
use App\Services\Tasks\TaskScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReportService
{
    public function __construct(
        private TaskScope $scope,
        private MailAccessScope $mailAccess,
    ) {}

    /**
     * Report dataset scoped to viewer and optional created-at date range
     * (RPT-001/002).
     *
     * @return Builder<Task>
     */
    public function query(User $viewer, ?string $from, ?string $to): Builder
    {
        $query = $this->scope->query($viewer)->with(['department', 'assignedTo:id,full_name,title', 'workflowSteps.sender:id,full_name', 'workflowSteps.recipient:id,full_name']);

        if ($from !== null && $from !== '') {
            $query->whereDate('tasks.created_at', '>=', Carbon::parse($from));
        }
        if ($to !== null && $to !== '') {
            $query->whereDate('tasks.created_at', '<=', Carbon::parse($to));
        }

        return $query;
    }

    /**
     * The six register totals shown above the report tables.
     *
     * For the PS these span the entire correspondence register — every one of
     * the 73k rows carries their office_supervisor_user_id, so no index can
     * narrow the set — and running them as six separate COUNT(*) queries
     * measured 11.7 s. Folding them into a single conditional aggregate makes
     * it one pass instead of six (4.7 s), and caching means only the first
     * viewer in the window pays even that. The totals move slowly enough that
     * a minute of staleness is not meaningful on a reporting screen.
     *
     * @param  Builder<MailRecord>  $query
     * @return array<string, int>
     */
    private function correspondenceSummary(User $viewer, Builder $query, ?string $from, ?string $to): array
    {
        $key = 'ats:reports:correspondence:'.$viewer->id.':'.md5(($from ?? '').'|'.($to ?? ''));

        return Cache::flexible($key, [60, 600], function () use ($query) {
            $row = (clone $query)
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('SUM(direction = ?) AS incoming', ['incoming'])
                ->selectRaw('SUM(direction = ?) AS outgoing', ['outgoing'])
                ->selectRaw('SUM(status IN (?, ?)) AS drafts', ['draft', 'rejected'])
                ->selectRaw('SUM(status IN (?, ?, ?)) AS awaiting_action', ['received', 'registered', 'awaiting_review'])
                ->selectRaw('SUM(status IN (?, ?, ?)) AS completed_archived', ['completed', 'delivered', 'archived'])
                ->first();

            return [
                'total' => (int) ($row->total ?? 0),
                'incoming' => (int) ($row->incoming ?? 0),
                'outgoing' => (int) ($row->outgoing ?? 0),
                'drafts' => (int) ($row->drafts ?? 0),
                'awaiting_action' => (int) ($row->awaiting_action ?? 0),
                'completed_archived' => (int) ($row->completed_archived ?? 0),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $viewer, ?string $from, ?string $to): array
    {
        /** @var Collection<int, Task> $tasks */
        $tasks = $this->query($viewer, $from, $to)->get();

        $summary = $this->summarise($tasks);
        $correspondenceQuery = MailRecord::query();
        $this->mailAccess->apply($correspondenceQuery, $viewer);
        if ($from !== null && $from !== '') {
            $correspondenceQuery->whereDate('mail_records.created_at', '>=', Carbon::parse($from));
        }
        if ($to !== null && $to !== '') {
            $correspondenceQuery->whereDate('mail_records.created_at', '<=', Carbon::parse($to));
        }

        $departments = $tasks
            ->groupBy(fn (Task $task) => $task->department_id === null ? 'central' : (string) $task->department_id)
            ->map(function (Collection $deptTasks) {
                $officerRows = $deptTasks
                    ->groupBy(fn (Task $task) => $task->assigned_to_user_id === null
                        ? 'snapshot-'.$task->assigned_to_name_snapshot
                        : 'user-'.$task->assigned_to_user_id)
                    ->map(function (Collection $officerTasks) {
                        /** @var Task $firstTask */
                        $firstTask = $officerTasks->first();

                        return [
                            'officer_id' => $firstTask->assigned_to_user_id,
                            'officer' => $firstTask->assigned_to_name_snapshot,
                            'title' => $firstTask->assignedTo?->title,
                            ...$this->summarise($officerTasks),
                        ];
                    })
                    ->sortBy('officer')
                    ->values()
                    ->all();

                /** @var Task $firstTask */
                $firstTask = $deptTasks->first();

                return [
                    'id' => $firstTask->department_id,
                    'name' => $firstTask->department?->name ?? 'Central / Office of the PS',
                    ...$this->summarise($deptTasks),
                    'officers' => $officerRows,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'summary' => $summary,
            'correspondenceSummary' => $this->correspondenceSummary($viewer, $correspondenceQuery, $from, $to),
            'departments' => $departments,
            'statusBreakdown' => collect(TaskStatus::cases())
                ->map(function (TaskStatus $status) use ($tasks, $summary) {
                    $count = $tasks->where('workflow_status', $status)->count();

                    return [
                        'label' => $status->label(),
                        'badge_class' => $status->badgeClass(),
                        'count' => $count,
                        'percentage' => $summary['total'] === 0 ? 0 : (int) round($count / $summary['total'] * 100),
                    ];
                })
                ->filter(fn (array $row) => $row['count'] > 0)
                ->values()
                ->all(),
            'priorityBreakdown' => collect(Priority::cases())
                ->map(function (Priority $priority) use ($tasks, $summary) {
                    $count = $tasks->where('priority', $priority)->count();

                    return [
                        'label' => $priority->label(),
                        'badge_class' => $priority->badgeClass(),
                        'count' => $count,
                        'percentage' => $summary['total'] === 0 ? 0 : (int) round($count / $summary['total'] * 100),
                    ];
                })
                ->filter(fn (array $row) => $row['count'] > 0)
                ->values()
                ->all(),
            'workflowSummary' => [
                'created_by_me' => $tasks->where('creator_user_id', $viewer->id)->count(),
                'assigned_to_me' => $tasks->where('current_assignee_user_id', $viewer->id)->count(),
                'awaiting_my_review' => $tasks->where('current_reviewer_user_id', $viewer->id)->count(),
                'returned_for_correction' => $tasks->where('review_status', 'returned')->count(),
                'direct_assignments' => $tasks->filter(fn (Task $task) => $task->workflowSteps->contains(fn ($step) => $step->sequence > 1 && $step->is_direct))->count(),
                'average_route_levels' => $tasks->isEmpty() ? 0 : round((float) $tasks->avg(fn (Task $task) => $task->workflowSteps->count()), 1),
            ],
        ];
    }

    /**
     * Rows for CSV export (RPT-005), shaped once so screen and export
     * can never disagree.
     *
     * @return list<array<string, string>>
     */
    public function exportRows(User $viewer, ?string $from, ?string $to): array
    {
        return $this->query($viewer, $from, $to)
            ->orderBy('reference')
            ->get()
            ->map(fn (Task $task) => [
                'Reference' => $task->reference,
                'Title' => $task->title,
                'Level' => $task->assignment_level->label(),
                'Assignee' => $task->assigned_to_name_snapshot,
                'Department' => $task->department?->name ?? 'Central / Office of the PS',
                'Priority' => $task->priority->label(),
                'Due Date' => $task->due_date?->format('Y-m-d') ?? '',
                'Status' => $task->workflow_status->label(),
                'Progress' => $task->progress_percent.'%',
                'Created At' => $task->created_at->format('Y-m-d H:i'),
                'Completed At' => $task->completed_at?->format('Y-m-d H:i') ?? '',
                'Execution Status' => str($task->execution_status)->replace('_', ' ')->title()->toString(),
                'Review Status' => str($task->review_status)->replace('_', ' ')->title()->toString(),
                'Approval Status' => str($task->approval_status)->replace('_', ' ')->title()->toString(),
                'Actual Delegation Route' => $task->workflowSteps->map(fn ($step) => ($step->sender?->full_name ?? 'Former user').' → '.($step->recipient?->full_name ?? 'Former user'))->implode(' | '),
            ])->all();
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array{total: int, completed: int, active: int, awaiting_review: int, overdue: int, completion_rate: int, overdue_rate: int, average_progress: int, on_time_rate: int|null}
     */
    private function summarise(Collection $tasks): array
    {
        $total = $tasks->count();
        $completed = $tasks->where('workflow_status', TaskStatus::Completed)->count();
        $overdue = $tasks->filter(fn (Task $task) => $task->overdue)->count();
        $dueCompleted = $tasks->filter(fn (Task $task) => $task->workflow_status === TaskStatus::Completed
            && $task->due_date !== null
            && $task->completed_at !== null);
        $onTime = $dueCompleted->filter(fn (Task $task) => $task->completed_at->lte($task->due_date->endOfDay()))->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'active' => $tasks->filter(fn (Task $task) => ! $task->workflow_status->isClosed())->count(),
            'awaiting_review' => $tasks->where('workflow_status', TaskStatus::AwaitingReview)->count(),
            'overdue' => $overdue,
            'completion_rate' => $total === 0 ? 0 : (int) round($completed / $total * 100),
            'overdue_rate' => $total === 0 ? 0 : (int) round($overdue / $total * 100),
            'average_progress' => $total === 0 ? 0 : (int) round((float) $tasks->avg('progress_percent')),
            'on_time_rate' => $dueCompleted->isEmpty() ? null : (int) round($onTime / $dueCompleted->count() * 100),
        ];
    }
}
