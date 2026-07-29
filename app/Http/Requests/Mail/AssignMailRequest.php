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
            'assigned_to_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->where('locked', false)->whereNull('deleted_at'))],
            'priority' => ['required', Rule::enum(Priority::class)],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'workstream_id' => ['nullable', 'integer', Rule::exists('workstreams', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('assigned_to_user_id')) {
                return;
            }

            $recipient = User::query()->find($this->integer('assigned_to_user_id'));
            $allowed = $recipient !== null && app(RecipientSearchService::class)->isAssignable($this->user(), $recipient);

            if (! $allowed) {
                $validator->errors()->add('assigned_to_user_id', 'The selected recipient is unavailable or outside your authorised assignment scope.');
            } elseif ($recipient->department_id !== ($this->filled('department_id') ? $this->integer('department_id') : null)) {
                $validator->errors()->add('assigned_to_user_id', 'The selected recipient no longer belongs to the submitted department. Search and select the recipient again.');
            }
        });
    }
}
