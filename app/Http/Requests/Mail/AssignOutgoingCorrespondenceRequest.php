<?php

namespace App\Http\Requests\Mail;

use App\Models\MailRecord;
use App\Services\Mail\RecipientSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class AssignOutgoingCorrespondenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var MailRecord $mail */
        $mail = $this->route('mail');

        return $this->user()->can('assignOutgoing', $mail);
    }

    public function rules(): array
    {
        $extensions = implode(',', config('ats.mail.allowed_extensions'));

        return [
            'assigned_to_user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('active', true)->where('locked', false)->whereNull('deleted_at')),
            ],
            'cc_user_ids' => ['nullable', 'array', 'max:50'],
            'cc_user_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('active', true)->where('locked', false)->whereNull('deleted_at')),
            ],
            'instructions' => ['required', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'attachments' => ['nullable', 'array', 'max:'.config('ats.mail.max_items')],
            'attachments.*' => [
                'file',
                File::types(explode(',', $extensions))->max(config('ats.mail.max_size_kb')),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $primaryId = $this->integer('assigned_to_user_id');
            $ccIds = collect($this->input('cc_user_ids', []))->map(fn ($id) => (int) $id)->filter()->unique();
            if ($ccIds->contains($primaryId)) {
                $validator->errors()->add('cc_user_ids', 'The responsible officer cannot also be selected under CC.');

                return;
            }

            $recipientIds = $ccIds->push($primaryId)->unique()->values();
            $allowed = app(RecipientSearchService::class)->assignableUsers($this->user())
                ->whereKey($recipientIds)->count();
            if ($allowed !== $recipientIds->count()) {
                $validator->errors()->add('assigned_to_user_id', 'One or more selected recipients are unavailable or outside your authorised assignment scope.');
            }
        });
    }
}
