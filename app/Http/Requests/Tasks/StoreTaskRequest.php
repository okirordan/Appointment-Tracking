<?php

namespace App\Http\Requests\Tasks;

use App\Enums\Priority;
use App\Models\Task;
use App\Services\Tasks\TaskScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Task::class);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_to_user_id' => ['required', 'integer', 'min:1'],
            'priority' => ['required', Rule::enum(Priority::class)],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'workstream_id' => ['nullable', 'integer', Rule::exists('workstreams', 'id')->where(fn ($query) => $query->where('active', true)->whereNull('deleted_at'))],
        ];
    }

    /**
     * The assignee must be an available Ministry user. Department boundaries
     * do not restrict recipient visibility; create permission is enforced
     * independently by the policy and middleware.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('assigned_to_user_id')) {
                return;
            }

            $allowed = app(TaskScope::class)
                ->assignableUsers($this->user())
                ->whereKey($this->integer('assigned_to_user_id'))
                ->exists();

            if (! $allowed) {
                $validator->errors()->add('assigned_to_user_id', 'You cannot assign tasks to this person.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'assigned_to_user_id.required' => 'An assignee is required.',
            'assigned_to_user_id.min' => 'An assignee is required.',
            'due_date.after_or_equal' => 'The due date cannot be in the past.',
        ];
    }
}
