<?php

namespace App\Http\Requests\Mail;

use App\Enums\Priority;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\Mail\RecipientSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignMailRequest extends FormRequest
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
        /** @var MailRecord $mail */
        $mail = $this->route('mail');

        return $this->user()->can('assign', $mail);
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::in(['individual', 'multiple', 'office', 'department'])],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
            'organizational_unit_id' => ['nullable', 'integer', 'required_if:target_type,office', Rule::exists('organizational_units', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
            'target_department_id' => ['nullable', 'integer', 'required_if:target_type,department', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
            'assigned_to_user_id' => ['nullable', 'integer', 'required_without_all:assigned_to_user_ids,organizational_unit_id,target_department_id', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->where('locked', false)->whereNull('deleted_at'))],
            'assigned_to_user_ids' => ['nullable', 'array', 'min:1', 'required_if:target_type,multiple'],
            'assigned_to_user_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->where('locked', false)->whereNull('deleted_at'))],
            'priority' => ['required', Rule::enum(Priority::class)],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'workstream_id' => ['nullable', 'integer', Rule::exists('workstreams', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,mp4,webm'],
        ];
    }

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
            $recipients = User::query()->whereKey($ids)->get();
            $allowedIds = app(RecipientSearchService::class)
                ->assignableUsers($this->user())
                ->whereKey($ids)
                ->pluck('id');

            if ($ids->isEmpty() || $allowedIds->count() !== $ids->count()) {
                $field = $this->filled('assigned_to_user_id')
                    ? 'assigned_to_user_id'
                    : 'assigned_to_user_ids';
                $validator->errors()->add($field, 'One or more selected recipients are unavailable or outside your authorised assignment scope.');
            }
        });
    }
}
