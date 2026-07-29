<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\UpdateProgressRequest;
use App\Models\Task;
use App\Services\Tasks\TaskService;
use Illuminate\Http\RedirectResponse;

class ProgressController extends Controller
{
    public function __construct(private TaskService $service) {}

    public function store(UpdateProgressRequest $request, Task $task): RedirectResponse
    {
        $this->service->updateProgress(
            $request->user(),
            $task,
            $request->safe()->only(['status', 'progress', 'note']),
            $request->file('evidence', []),
            array_values(array_filter(
                $request->validated('evidence_links', []),
                fn ($link) => is_string($link) && trim($link) !== '',
            )),
        );

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', 'Progress updated.');
    }
}
