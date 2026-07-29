<?php

namespace App\Services\Performance;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PerformanceMetricsService
{
    public function taskQuery(?string $from, ?string $to): Builder
    {
        return Task::query()
            ->when($from, fn (Builder $q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('created_at', '<=', $to));
    }

    public function calculate(Builder $query, ?string $to = null): array
    {
        /** @var Collection<int, Task> $tasks */
        $tasks = $query->get(['id', 'workflow_status', 'progress_percent', 'priority', 'due_date', 'completed_at', 'created_at']);

        return $this->calculateCollection($tasks, $to);
    }

    public function calculateCollection(Collection $tasks, ?string $to = null): array
    {
        $cutoff = $to ? Carbon::parse($to)->endOfDay() : now();
        $total = $tasks->count();
        $completed = $tasks->filter(fn (Task $t) => $t->completed_at !== null && $t->completed_at->lte($cutoff));
        $active = $tasks->filter(fn (Task $t) => $t->created_at?->lte($cutoff) !== false && ($t->completed_at === null || $t->completed_at->gt($cutoff)) && $t->workflow_status !== TaskStatus::Archived);
        $overdue = $active->filter(fn (Task $t) => $t->due_date !== null && $t->due_date->endOfDay()->lt($cutoff));
        $dueCompleted = $completed->filter(fn (Task $t) => $t->due_date !== null);
        $onTime = $dueCompleted->filter(fn (Task $t) => $t->completed_at->lte($t->due_date->endOfDay()));

        return [
            'assigned' => $total,
            'completed' => $completed->count(),
            'active' => $active->count(),
            'overdue' => $overdue->count(),
            'average_progress' => $total === 0 ? null : round((float) $tasks->avg('progress_percent'), 1),
            'completion_rate' => $total === 0 ? null : round($completed->count() / $total * 100, 1),
            'on_time_rate' => $dueCompleted->isEmpty() ? null : round($onTime->count() / $dueCompleted->count() * 100, 1),
            'late_completed' => $dueCompleted->count() - $onTime->count(),
            'high_priority_overdue' => $overdue->whereIn('priority', ['high', 'urgent'])->count(),
            'eligible_for_rank' => $total >= (int) config('ats.performance.minimum_sample', 5),
        ];
    }

    public function rank(array $rows): array
    {
        $eligible = collect($rows)->filter(fn ($row) => $row['metrics']['eligible_for_rank'])
            ->sortBy([fn ($a, $b) => ($b['metrics']['completion_rate'] <=> $a['metrics']['completion_rate']), fn ($a, $b) => (($b['metrics']['on_time_rate'] ?? -1) <=> ($a['metrics']['on_time_rate'] ?? -1)), fn ($a, $b) => ($a['metrics']['overdue'] <=> $b['metrics']['overdue'])]);
        $rank = 0;
        $position = 0;
        $previous = null;
        $ranked = [];
        foreach ($eligible as $row) {
            $position++;
            $key = implode('|', [$row['metrics']['completion_rate'], $row['metrics']['on_time_rate'] ?? 'n', $row['metrics']['overdue']]);
            if ($key !== $previous) {
                $rank = $position;
            }
            $ranked[$row['id']] = $rank;
            $previous = $key;
        }

        return array_map(function ($row) use ($ranked) {
            $row['rank'] = $ranked[$row['id']] ?? null;
            $rate = $row['metrics']['completion_rate'];
            $row['band'] = ! $row['metrics']['eligible_for_rank'] ? 'Insufficient data' : ($rate >= 80 && $row['metrics']['overdue'] <= max(1, $row['metrics']['assigned'] * .1) ? 'Strong' : ($rate >= 60 ? 'Stable' : 'Needs attention'));

            return $row;
        }, $rows);
    }
}
