<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Services\Mail\RecipientSearchService;
use App\Services\SecretaryAuthorityService;
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
        $this->authorize('create', Task::class);

        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['users' => []]);
        }

        if ($request->boolean('include_groups')) {
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

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $users = $this->scope->assignableUsers($request->user());
        if ($request->boolean('department_only') && $this->secretaryAuthority->supportedDepartmentId($request->user()) !== null) {
            $users->whereIn('users.id', $this->secretaryAuthority->departmentOfficers($request->user())->select('users.id'));
        }

        $users = $users
            ->where(function ($query) use ($like) {
                $query->where('full_name', 'like', $like)
                    ->orWhere('title', 'like', $like);
            })
            ->orderBy('full_name')
            ->limit(8)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'key' => 'user:'.$user->id,
                'target_type' => 'individual',
                'full_name' => $user->full_name,
                'title' => $user->title,
                'department_id' => $user->department_id,
                'initials' => $user->initials(),
            ]);

        return response()->json(['users' => $users]);
    }
}
