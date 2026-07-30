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
    public function authorize(): bool
    {
        /** @var MailRecord $mail */
        $mail = $this->route('mail');

        return $this->user()->can('assign', $mail);
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
            'assigned_to_user_id' => ['nullable', 'integer', 'required_without:assigned_to_user_ids', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->where('locked', false)->whereNull('deleted_at'))],
            'assigned_to_user_ids' => ['nullable', 'array', 'min:1', 'required_without:assigned_to_user_id'],
            'assigned_to_user_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->where('locked', false)->whereNull('deleted_at'))],
            'priority' => ['required', Rule::enum(Priority::class)],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'workstream_id' => ['nullable', 'integer', Rule::exists('workstreams', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
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
            } elseif ($recipients->contains(
                fn (User $recipient) => $recipient->department_id !== ($this->filled('department_id') ? $this->integer('department_id') : null),
            )) {
                $field = $this->filled('assigned_to_user_id')
                    ? 'assigned_to_user_id'
                    : 'assigned_to_user_ids';
                $validator->errors()->add($field, 'All selected recipients must belong to the submitted department. Search and select the recipients again.');
            }
        });
    }
}
