<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssigneeSearchController extends Controller
{
    public function __construct(private TaskScope $scope) {}

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

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $users = $this->scope->assignableUsers($request->user())
            ->where(function ($query) use ($like) {
                $query->where('full_name', 'like', $like)
                    ->orWhere('title', 'like', $like);
            })
            ->orderBy('full_name')
            ->limit(8)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'title' => $user->title,
                'initials' => $user->initials(),
            ]);

        return response()->json(['users' => $users]);
    }
}
