<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\MailRecord;
use App\Models\Task;
use App\Services\DepartmentAccessService;
use App\Services\SearchService;
use App\Services\SecretaryOfficeScope;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

class HomeController extends Controller
{
    public function __construct(
        private SearchService $search,
        private TaskScope $scope,
        private TaskPresenter $presenter,
        private SecretaryOfficeScope $secretaryOffices,
        private DepartmentAccessService $departments,
    ) {}

    /**
     * Home / Global Search (PRD §12.2): scoped search from ?q=, recent
     * searches, and the user's most recently updated tasks.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', 'all');
        abort_unless(in_array($type, ['all', 'mail', 'tasks', 'workstreams', 'departments', 'divisions', 'staff'], true), 422);
        $minChars = (int) config('ats.search.min_chars');
        $page = max(1, $request->integer('page', 1));

        // Every expensive prop is a closure so Inertia partial reloads (the
        // search form requests only q/type/results) skip the mail stats,
        // recent-task and recent-search queries entirely — a search visit
        // runs nothing but the search itself.
        $results = function () use ($user, $term, $type, $page, $minChars) {
            if (mb_strlen($term) < $minChars) {
                return null;
            }

            $found = $this->search->search(
                $user,
                $term,
                $type,
                true,
                $page,
                (int) config('ats.search.results_per_page', 20),
            );
            if ($page === 1) {
                // Search history is not required to render the response.
                defer(fn () => $this->search->remember($user, $term));
            }

            return $found;
        };

        $mailStats = function () use ($user) {
            if (! $user->can('viewAny', MailRecord::class)) {
                return null;
            }

            $key = "ats:home:mail-stats:{$user->id}";

            return Cache::flexible($key, [30, 180], function () use ($user) {
                $incomingBase = MailRecord::query()->where('direction', 'incoming');
                $outgoingBase = MailRecord::query()->where('direction', 'outgoing');

                if ($user->role === Role::Secretary) {
                    $this->secretaryOffices->applyMail($incomingBase, $user);
                    $this->secretaryOffices->applyMail($outgoingBase, $user);
                } elseif ($user->role === Role::Commissioner) {
                    $this->departments->applyMail($incomingBase, $user);
                    $this->departments->applyMail($outgoingBase, $user);
                }

                return [
                    'incoming_total' => (clone $incomingBase)->count(),
                    'awaiting_assignment' => (clone $incomingBase)->whereNull('task_id')->count(),
                    'assigned_total' => (clone $incomingBase)->whereNotNull('task_id')->count(),
                    'active_assignments' => (clone $incomingBase)
                        ->whereNotNull('task_id')
                        ->whereHas('task', fn ($task) => $task->whereNotIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value]))
                        ->count(),
                    'outgoing_total' => $outgoingBase->count(),
                ];
            });
        };

        return Inertia::render('home', [
            'q' => $term,
            'type' => $type,
            'results' => $results,
            'mailStats' => $mailStats,
            'recentSearches' => fn () => $this->search->recent($user),
            'recentTasks' => fn () => $this->scope->query($user)
                ->with('department')
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get()
                ->map(fn (Task $task) => $this->presenter->row($task))
                ->all(),
        ]);
    }
}
