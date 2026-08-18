<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreAnnotationRequest;
use App\Models\Task;
use App\Services\Tasks\TaskService;
use Illuminate\Http\RedirectResponse;

class AnnotationController extends Controller
{
    public function __construct(private TaskService $service) {}

    public function store(StoreAnnotationRequest $request, Task $task): RedirectResponse
    {
        $this->service->annotate($request->user(), $task, $request->validated());

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', 'Annotation saved successfully.');
    }
}
