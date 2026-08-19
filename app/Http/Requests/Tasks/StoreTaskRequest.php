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
    protected function prepareForValidation(): void
    {
        $this->merge([
            'target_type' => $this->input('target_type')
                ?: ($this->filled('organizational_unit_id') ? 'office'
                    : ($this->filled('target_department_id') ? 'department'
                        : (count((array) $this->input('assigned_to_user_ids', [])) > 1 ? 'multiple' : 'individual'))),
        ]);
    }

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
            'origin_title_id' => ['nullable', 'integer', Rule::exists('annotation_titles', 'id')->where('active', true)],
            'recipient_title_id' => ['nullable', 'integer', Rule::exists('annotation_titles', 'id')->where('active', true)],
            'target_type' => ['required', Rule::in(['individual', 'multiple', 'office', 'department'])],
            'organizational_unit_id' => ['nullable', 'integer', 'required_if:target_type,office', Rule::exists('organizational_units', 'id')->where(fn ($query) => $query->where('active', true)->whereNull('deleted_at'))],
            'target_department_id' => ['nullable', 'integer', 'required_if:target_type,department', Rule::exists('departments', 'id')->where(fn ($query) => $query->where('active', true)->whereNull('deleted_at'))],
            'assigned_to_user_id' => ['nullable', 'integer', 'min:1', 'required_without_all:assigned_to_user_ids,organizational_unit_id,target_department_id'],
            'assigned_to_user_ids' => ['nullable', 'array', 'min:1', 'required_if:target_type,multiple'],
            'assigned_to_user_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'priority' => ['required', Rule::enum(Priority::class)],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'workstream_id' => ['nullable', 'integer', Rule::exists('workstreams', 'id')->where(fn ($query) => $query->where('active', true)->whereNull('deleted_at'))],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,txt,csv'],
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
            if (! in_array($this->input('target_type'), ['individual', 'multiple'], true)) {
                return;
            }

            if ($validator->errors()->has('assigned_to_user_id')
                || $validator->errors()->has('assigned_to_user_ids')) {
                return;
            }

            $ids = collect($this->input('assigned_to_user_ids', []))
                ->when(
                    $this->filled('assigned_to_user_id'),
                    fn ($values) => $values->push($this->integer('assigned_to_user_id')),
                )
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values();
            $allowedCount = app(TaskScope::class)
                ->assignableUsers($this->user())
                ->whereKey($ids)
                ->count();

            if ($ids->isEmpty() || $allowedCount !== $ids->count()) {
                $field = $this->filled('assigned_to_user_id')
                    ? 'assigned_to_user_id'
                    : 'assigned_to_user_ids';
                $validator->errors()->add($field, 'One or more selected people are outside your authorised assignment scope.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'assigned_to_user_id.required' => 'An assignee is required.',
            'assigned_to_user_ids.required_without' => 'At least one assignee is required.',
            'assigned_to_user_id.min' => 'An assignee is required.',
            'due_date.after_or_equal' => 'The due date cannot be in the past.',
        ];
    }
}
