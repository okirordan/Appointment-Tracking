<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Workstream;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkstreamController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('create', Task::class), 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['project', 'programme', 'initiative', 'subject'])],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $normalized = Workstream::normalizeName($validated['name']);
        $existing = Workstream::withTrashed()->where('normalized_name', $normalized)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            if (! $existing->active) {
                $existing->update(['active' => true]);
            }

            return back()
                ->with('created_workstream_id', $existing->id)
                ->with('success', "{$existing->name} already exists and has been selected.");
        }

        try {
            $workstream = Workstream::create([
                ...$validated,
                'department_id' => null,
                'active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            $workstream = Workstream::withTrashed()->where('normalized_name', $normalized)->firstOrFail();
            if ($workstream->trashed()) {
                $workstream->restore();
            }
            if (! $workstream->active) {
                $workstream->update(['active' => true]);
            }
        }

        return back()
            ->with('created_workstream_id', $workstream->id)
            ->with('success', "{$workstream->name} is now available system-wide.");
    }
}
