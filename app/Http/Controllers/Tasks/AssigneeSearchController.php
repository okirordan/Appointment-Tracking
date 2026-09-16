<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\Mail\RecipientSearchService;
use App\Services\SecretaryAuthorityService;
use App\Services\Tasks\AssignmentTargetService;
use App\Services\Tasks\TaskScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssigneeSearchController extends Controller
{
    public function __construct(
        private TaskScope $scope,
        private RecipientSearchService $recipients,
        private SecretaryAuthorityService $secretaryAuthority,
    ) {}

    /**
     * Type-ahead assignee search for the New Task form. Results are
     * limited server-side to the creator's authorised scope.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $task = $request->filled('task_id') ? Task::findOrFail($request->integer('task_id')) : null;
        if ($task !== null) {
            $this->authorize('annotate', $task);
        } else {
            $this->authorize('create', Task::class);
        }

        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['users' => []]);
        }

        if ($task === null && $request->boolean('include_groups')) {
            $targets = collect($this->recipients->search($request->user(), $term, 15))
                ->map(fn (array $target) => [
                    'id' => $target['id'],
                    'key' => $target['key'],
                    'target_type' => $target['assignment_target_type'],
                    'full_name' => $target['name'],
                    'title' => $target['title'],
                    'department_id' => $target['department_id'],
                    'initials' => $target['initials'],
                ]);

            return response()->json(['users' => $targets]);
        }

        $users = $request->query('purpose') === 'origin'
            ? app(AssignmentTargetService::class)->eligibleUsers()
            : $this->scope->assignableUsers($request->user());
        if ($task !== null) {
            $ids = $task->participants()->where('active', true)->pluck('user_id')
                ->merge($task->workflowSteps()->pluck('recipient_user_id'))
                ->merge([$task->assigned_by_user_id, $task->assigned_to_user_id, $task->current_assignee_user_id, $task->creator_user_id]);
            $users->whereKey($ids->filter()->unique());
        }
        if ($request->boolean('department_only') && $this->secretaryAuthority->supportedDepartmentId($request->user()) !== null) {
            $users->whereIn('users.id', $this->secretaryAuthority->departmentOfficers($request->user())->select('users.id'));
        }

        $users = collect($this->recipients->search($request->user(), $term, 50, eligibleUsers: $users, includeGroups: false))
            ->map(fn (array $user) => [
                ...$user,
                'full_name' => $user['name'],
                'target_type' => 'individual',
            ]);

        return response()->json(['users' => $users]);
    }
}
