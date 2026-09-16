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
            'recipient_user_ids' => ['nullable', 'array', 'min:1', 'max:50'],
            'recipient_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'recipient_user_id' => ['required_without:recipient_user_ids', 'nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
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
        $ids = $data['recipient_user_ids'] ?? [$data['recipient_user_id']];
        $recipients = User::whereKey($ids)->get();
        abort_unless($this->scope->assignableUsers($request->user())->whereKey($ids)->count() === count($ids), 403);
        foreach ($recipients as $recipient) {
            if ($this->secretaryAuthority->supportedDepartmentId($request->user()) !== null) {
                abort_unless($this->secretaryAuthority->canAssignDepartmentOfficer($request->user(), $recipient), 403);
            }
        }
        $this->workflow->delegate($request->user(), $task, $recipients->all(), $data);

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
            'replacement_user_ids' => ['nullable', 'array', 'min:1', 'max:50'],
            'replacement_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('active', true)->where('locked', false))],
            'replacement_user_id' => ['required_without:replacement_user_ids', 'nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('active', true)->where('locked', false))],
            'from_user_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $ids = $data['replacement_user_ids'] ?? [$data['replacement_user_id']];
        abort_unless($this->scope->assignableUsers($request->user())->whereKey($ids)->count() === count($ids), 403);
        $replacements = User::whereKey($ids)->get();
        foreach ($replacements as $replacement) {
            if ($this->secretaryAuthority->supportedDepartmentId($request->user()) !== null) {
                abort_unless($this->secretaryAuthority->canAssignDepartmentOfficer($request->user(), $replacement), 403);
            }
        }
        $this->workflow->reassign($request->user(), $task, $replacements->all(), $data['reason'], $data['from_user_id'] ?? null);

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
            'resolution' => ['nullable', Rule::in(['reassign', 'file'])],
            'replacement_user_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $request->input('resolution') === 'reassign' && ! $request->filled('replacement_user_ids')),
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'replacement_user_ids' => ['nullable', 'array', 'min:1', 'max:50'],
            'replacement_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'resolution_note' => ['nullable', 'string', 'max:5000'],
            'filing_category' => ['nullable', 'string', 'max:120'],
            'confirmed' => ['required', 'accepted'],
        ]);

        $replacement = null;
        $replacements = [];
        if (($data['resolution'] ?? null) === 'reassign') {
            $replacements = User::whereKey($data['replacement_user_ids'] ?? [$data['replacement_user_id']])->get()->all();
            foreach ($replacements as $replacement) {
                abort_unless($this->scope->assignableUsers($request->user())->whereKey($replacement->id)->exists(), 403);
                if ($this->secretaryAuthority->supportedDepartmentId($request->user()) !== null) {
                    abort_unless($this->secretaryAuthority->canAssignDepartmentOfficer($request->user(), $replacement), 403);
                }
            }
            $replacement = $replacements[0];
        }

        $this->workflow->unassign(
            $request->user(),
            $task,
            $data['user_ids'],
            $data['reason'],
            $data['comments'] ?? null,
            [
                'action' => $data['resolution'] ?? null,
                'replacement' => $replacement,
                'replacements' => $replacements,
                'note' => $data['resolution_note'] ?? null,
                'filing_category' => $data['filing_category'] ?? null,
            ],
        );

        $message = match ($data['resolution'] ?? null) {
            'reassign' => "Assignment withdrawn and reassigned to {$replacement->full_name}. The complete history has been preserved.",
            'file' => 'Assignment withdrawn and correspondence filed. The complete history has been preserved.',
            default => 'Selected user(s) unassigned. The task and its complete history remain available for reassignment.',
        };

        return back()->with('success', $message);
    }
}
