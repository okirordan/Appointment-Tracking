<?php

namespace App\Http\Requests\Mail;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Services\DepartmentAccessService;
use App\Services\Mail\MailFeatureSettings;
use App\Services\Mail\RecipientSearchService;
use App\Services\SecretaryOfficeScope;
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
        $features = app(MailFeatureSettings::class);
        $outgoing = $this->routeIs('mail.outgoing.store');

        $this->merge([
            'direction' => $outgoing ? 'outgoing' : 'incoming',
            'sender_name' => trim((string) $this->input('sender_name')),
            'register_number' => $features->enabled('register_number') ? $this->nullableTrimmedString('register_number') : null,
            'subject' => trim((string) $this->input('subject')),
            'details' => $this->nullableTrimmedString('details'),
            'correspondence_reference' => $features->enabled('correspondence_reference')
                ? $this->nullableTrimmedString('correspondence_reference') : null,
            'receipt_method' => $features->enabled('receipt_method') ? $this->input('receipt_method') : null,
            'confidentiality' => $features->enabled('confidentiality') ? ($this->input('confidentiality') ?: 'normal') : 'normal',
            'registry_file_number' => $features->enabled('registry_file_number')
                ? $this->nullableTrimmedString('registry_file_number') : null,
            'duplicate_reason' => $this->nullableTrimmedString('duplicate_reason'),
            'priority' => $features->enabled('priority') ? ($this->input('priority') ?: 'medium') : 'medium',
            'status' => $features->enabled('initial_status')
                ? ($this->input('status') ?: ($outgoing ? 'draft' : 'registered'))
                : ($outgoing ? ($this->boolean('requires_follow_up') ? 'dispatched' : 'draft') : 'registered'),
            'requires_follow_up' => $outgoing && $this->boolean('requires_follow_up'),
        ]);
    }

    public function rules(): array
    {
        $extensions = implode(',', config('ats.mail.allowed_extensions'));

        return [
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'register_number' => ['nullable', 'string', 'max:255', Rule::unique('mail_records', 'register_number')],
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
            'requires_follow_up' => ['required', 'boolean'],
            'assigned_to_user_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('direction') === 'outgoing' && $this->boolean('requires_follow_up')),
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('active', true)->where('locked', false)->whereNull('deleted_at')),
            ],
            'cc_user_ids' => ['nullable', 'array', 'max:50'],
            'cc_user_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('active', true)->where('locked', false)->whereNull('deleted_at')),
            ],
            'instructions' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('direction') === 'outgoing' && $this->boolean('requires_follow_up')),
                'string', 'max:5000',
            ],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('direction') === 'outgoing') {
                if ($this->boolean('requires_follow_up') && ! $this->user()->can('createOutgoingAssignment', MailRecord::class)) {
                    $validator->errors()->add('requires_follow_up', 'You are not authorised to create follow-up assignments from outgoing correspondence.');
                }
                if ($this->boolean('requires_follow_up') && $this->input('status') !== 'dispatched') {
                    $validator->errors()->add('status', 'Outgoing correspondence must be dispatched before a follow-up assignment can be issued.');
                }
                if (! $this->boolean('requires_follow_up') && $this->filled('due_date')) {
                    $validator->errors()->add('due_date', 'A due date applies only when follow-up action is required.');
                }

                $primaryId = $this->integer('assigned_to_user_id');
                $ccIds = collect($this->input('cc_user_ids', []))->map(fn ($id) => (int) $id)->filter()->unique();
                if ($primaryId > 0 && $ccIds->contains($primaryId)) {
                    $validator->errors()->add('cc_user_ids', 'The responsible officer cannot also be selected under CC.');
                }

                $recipientIds = $ccIds->when($primaryId > 0, fn ($ids) => $ids->push($primaryId))->unique()->values();
                if ($recipientIds->isNotEmpty()) {
                    $allowed = app(RecipientSearchService::class)->assignableUsers($this->user())
                        ->whereKey($recipientIds)->count();
                    if ($allowed !== $recipientIds->count()) {
                        $validator->errors()->add('assigned_to_user_id', 'One or more selected recipients are unavailable or outside your authorised assignment scope.');
                    }
                }
            }

            if ($validator->errors()->isNotEmpty() || $this->boolean('duplicate_override')) {
                return;
            }

            $dateColumn = $this->input('direction') === 'incoming' ? 'received_date' : 'sent_date';
            $sender = trim((string) $this->input('sender_name'));
            $subject = trim((string) $this->input('subject'));
            $details = $this->nullableTrimmedString('details');
            $referenceFeatureEnabled = app(MailFeatureSettings::class)->enabled('correspondence_reference');
            $reference = $this->nullableTrimmedString('correspondence_reference');

            $duplicates = MailRecord::query();
            if ($this->user()->role === Role::Secretary) {
                app(SecretaryOfficeScope::class)->applyMail($duplicates, $this->user());
            } elseif (app(DepartmentAccessService::class)->scopesMail($this->user())) {
                app(DepartmentAccessService::class)->applyMail($duplicates, $this->user());
            }

            $duplicate = $duplicates
                ->where('direction', $this->input('direction'))
                ->where('sender_name', $sender)
                ->where('subject', $subject)
                ->when(
                    $this->input($dateColumn),
                    fn ($query, $date) => $query->whereDate($dateColumn, $date),
                    fn ($query) => $query->whereNull($dateColumn),
                )
                ->when($referenceFeatureEnabled, fn ($query) => $query->when(
                    $reference === null,
                    fn ($referenceQuery) => $referenceQuery->where(fn ($missing) => $missing
                        ->whereNull('correspondence_reference')->orWhere('correspondence_reference', '')),
                    fn ($referenceQuery) => $referenceQuery->where('correspondence_reference', $reference),
                ))
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
