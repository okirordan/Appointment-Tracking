<?php

namespace App\Http\Requests\Mail;

use App\Models\MailRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MailRecord::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'direction' => $this->routeIs('mail.outgoing.store') ? 'outgoing' : 'incoming',
            'sender_name' => trim((string) $this->input('sender_name')),
            'subject' => trim((string) $this->input('subject')),
            'details' => $this->nullableTrimmedString('details'),
            'correspondence_reference' => $this->nullableTrimmedString('correspondence_reference'),
            'duplicate_reason' => $this->nullableTrimmedString('duplicate_reason'),
            'priority' => $this->input('priority') ?: 'medium',
            'status' => $this->input('status') ?: ($this->routeIs('mail.outgoing.store') ? 'draft' : 'registered'),
        ]);
    }

    public function rules(): array
    {
        $extensions = implode(',', config('ats.mail.allowed_extensions'));

        return [
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_organisation' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:500'],
            'details' => ['nullable', 'string', 'max:10000'],
            'correspondence_reference' => ['nullable', 'string', 'max:255'],
            'letter_date' => ['nullable', 'date'],
            'received_date' => ['required_if:direction,incoming', 'nullable', 'date'],
            'sent_date' => ['nullable', 'date'],
            'receipt_method' => ['nullable', Rule::in(['hand', 'courier', 'email', 'post', 'other'])],
            'confidentiality' => ['required', Rule::in(['normal', 'confidential', 'restricted'])],
            'registry_file_number' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['received', 'registered', 'draft', 'dispatched'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'attachments' => ['nullable', 'array', 'max:'.config('ats.mail.max_items')],
            'attachments.*' => [
                'file',
                File::types(explode(',', $extensions))->max(config('ats.mail.max_size_kb')),
            ],
            'duplicate_override' => ['sometimes', 'boolean'],
            'duplicate_reason' => ['nullable', Rule::requiredIf(fn () => $this->boolean('duplicate_override')), 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->boolean('duplicate_override')) {
                return;
            }

            $dateColumn = $this->input('direction') === 'incoming' ? 'received_date' : 'sent_date';
            $sender = trim((string) $this->input('sender_name'));
            $subject = trim((string) $this->input('subject'));
            $details = $this->nullableTrimmedString('details');
            $reference = $this->nullableTrimmedString('correspondence_reference');

            $duplicate = MailRecord::query()
                ->where('direction', $this->input('direction'))
                ->where('sender_name', $sender)
                ->where('subject', $subject)
                ->when(
                    $this->input($dateColumn),
                    fn ($query, $date) => $query->whereDate($dateColumn, $date),
                    fn ($query) => $query->whereNull($dateColumn),
                )
                ->when(
                    $reference === null,
                    fn ($query) => $query->where(fn ($missing) => $missing->whereNull('correspondence_reference')->orWhere('correspondence_reference', '')),
                    fn ($query) => $query->where('correspondence_reference', $reference),
                )
                ->when(
                    $details === null,
                    fn ($query) => $query->where(fn ($missing) => $missing->whereNull('details')->orWhere('details', '')),
                    fn ($query) => $query->where('details', $details),
                )
                ->first();

            if ($duplicate !== null) {
                $validator->errors()->add(
                    'duplicate_override',
                    "Possible duplicate of {$duplicate->register_number}. Confirm below only if this is a separate mail record."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'duplicate_reason.required' => 'Please briefly explain why this mail is not a duplicate before saving.',
            'duplicate_reason.required_if' => 'Please briefly explain why this mail is not a duplicate before saving.',
        ];
    }

    private function nullableTrimmedString(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }
}
