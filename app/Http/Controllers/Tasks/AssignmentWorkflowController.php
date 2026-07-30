<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Task;
use App\Models\User;
use App\Services\SecretaryAuthorityService;
use App\Services\Tasks\AssignmentWorkflowService;
use App\Services\Tasks\TaskScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentWorkflowController extends Controller
{
    public function __construct(
        private AssignmentWorkflowService $workflow,
        private SecretaryAuthorityService $secretaryAuthority,
        private TaskScope $scope,
    ) {}

    public function delegate(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('delegate', $task);
        $data = $request->validate([
            'recipient_user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true)->where('locked', false)],
            'instructions' => ['required', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
            'is_direct' => ['sometimes', 'boolean'],
        ]);
        if (($data['is_direct'] ?? false)
            && ! $request->user()->can('assignments.direct')
            && ! $this->secretaryAuthority->allows($request->user(), 'assignments.direct')
            && ! in_array($request->user()->role->value, ['sysadmin', 'ps', 'commissioner'], true)) {
            abort(403);
        }
        $recipient = User::findOrFail($data['recipient_user_id']);
        abort_unless($this->scope->assignableUsers($request->user())->whereKey($recipient->id)->exists(), 403);
        $this->workflow->delegate($request->user(), $task, $recipient, $data);

        return back()->with('success', 'Assignment delegated and the workflow route updated.');
    }

    public function submit(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('submit', $task);
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $this->workflow->submit($request->user(), $task, $data['note']);

        return back()->with('success', 'Work submitted to the previous delegation level for review.');
    }

    public function review(Request $request, AssignmentSubmission $submission): RedirectResponse
    {
        $this->authorize('review', $submission->task);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return', 'reject', 'request_information'])],
            'comments' => ['required', 'string', 'max:5000'],
            'revised_due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $permission = match ($data['decision']) {
            'approve' => 'assignments.approve',
            'return', 'request_information' => 'assignments.return',
            'reject' => 'assignments.reject',
        };
        $legacyAllowed = in_array($request->user()->role->value, ['sysadmin', 'ps', 'commissioner'], true);
        abort_unless($request->user()->can($permission) || $this->secretaryAuthority->allows($request->user(), $permission) || $legacyAllowed, 403);
        $this->workflow->review($request->user(), $submission, $data);

        return back()->with('success', 'Review decision recorded.');
    }

    public function reassign(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('reassign', $task);
        $data = $request->validate([
            'replacement_user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true)->where('locked', false)],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $replacement = User::findOrFail($data['replacement_user_id']);
        abort_unless($this->scope->assignableUsers($request->user())->whereKey($replacement->id)->exists(), 403);
        $this->workflow->reassign($request->user(), $task, $replacement, $data['reason']);

        return back()->with('success', 'Current workflow step reassigned with history preserved.');
    }

    public function unassign(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('unassign', $task);
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'reason' => ['required', 'string', 'max:2000'],
            'comments' => ['nullable', 'string', 'max:5000'],
            'confirmed' => ['required', 'accepted'],
        ]);

        $this->workflow->unassign(
            $request->user(),
            $task,
            $data['user_ids'],
            $data['reason'],
            $data['comments'] ?? null,
        );

        return back()->with('success', 'Selected user(s) unassigned. The task and its complete history remain available for reassignment.');
    }
}
