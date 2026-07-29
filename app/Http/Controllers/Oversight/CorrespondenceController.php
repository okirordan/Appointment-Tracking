<?php

namespace App\Http\Controllers\Oversight;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\TaskHistory;
use App\Services\Tasks\TaskScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CorrespondenceController extends Controller
{
    public function __construct(private TaskScope $scope) {}

    /**
     * Commissioner Correspondence (PRD §12.12): annotation/instruction
     * feed for tasks in the viewer's scope, grouped by officer, newest
     * first, filterable by officer or task text.
     */
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();
        $term = trim((string) $request->query('q', ''));

        $taskIds = $this->scope->query($viewer)->pluck('tasks.id');

        $query = TaskHistory::whereIn('task_id', $taskIds)
            ->where('action_type', 'Annotated')
            ->with(['task:id,reference,title,assigned_to_user_id,assigned_to_name_snapshot', 'task.assignedTo:id,full_name,title']);

        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q
                ->where('note', 'like', $like)
                ->orWhereHas('task', fn ($t) => $t
                    ->where('title', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('assigned_to_name_snapshot', 'like', $like)));
        }

        $annotations = $query->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Grouped by the assigned officer (CORR: group by officer title).
        $groups = collect($annotations->items())
            ->groupBy(fn (TaskHistory $entry) => $entry->task?->assigned_to_name_snapshot ?? 'Unassigned')
            ->map(fn ($entries, string $officer) => [
                'officer' => $officer,
                'officer_title' => $entries->first()->task?->assignedTo?->title,
                'items' => $entries->map(fn (TaskHistory $entry) => [
                    'id' => $entry->id,
                    'author' => $entry->performed_by_name_snapshot,
                    'author_role' => $entry->performed_by_title_snapshot
                        ?? ($entry->performed_by_role === null ? null : (Role::tryFrom($entry->performed_by_role)?->label() ?? str($entry->performed_by_role)->replace('_', ' ')->title()->toString())),
                    'text' => $entry->note,
                    'when_label' => $entry->created_at->format('d/m/Y H:i'),
                    'task_id' => $entry->task_id,
                    'task_reference' => $entry->task?->reference,
                    'task_title' => $entry->task?->title,
                ])->values()->all(),
            ])->values()->all();

        return Inertia::render('oversight/correspondence', [
            'q' => $term,
            'groups' => $groups,
            'meta' => [
                'current_page' => $annotations->currentPage(),
                'last_page' => $annotations->lastPage(),
                'total' => $annotations->total(),
            ],
        ]);
    }
}
