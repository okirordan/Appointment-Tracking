<?php

namespace App\Http\Requests\Mail;

use App\Models\AnnotationTitle;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\Mail\MailDuplicateService;
use App\Services\Mail\MailFeatureSettings;
use App\Services\Mail\RecipientSearchService;
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
        $sourceType = $outgoing ? null : trim((string) $this->input('source_type'));
        if (! $outgoing && $sourceType === '' && $this->filled('sender_name')) {
            // Preserve compatibility with older integrations while the new UI
            // always sends an explicit incoming source type.
            $sourceType = 'external';
        }
        $externalSource = $outgoing ? null : $this->nullableTrimmedString('external_source');
        if (! $outgoing && $sourceType === 'external' && $externalSource === null) {
            $externalSource = $this->nullableTrimmedString('sender_name');
        }
        $senderName = trim((string) $this->input('sender_name'));
        $sourceDirectoryType = trim((string) $this->input('source_directory_type'));
        if (! $outgoing && $sourceType === 'internal' && $sourceDirectoryType === '') {
            $sourceDirectoryType = is_numeric($this->input('source_staff_user_id')) ? 'staff' : 'shorthand';
        }
        if (! $outgoing && $sourceType === 'internal' && $sourceDirectoryType === 'shorthand' && is_numeric($this->input('annotation_title_id'))) {
            $title = AnnotationTitle::query()->where('active', true)->find((int) $this->input('annotation_title_id'));
            $senderName = $title === null ? '' : "{$title->shorthand} — {$title->full_title}";
        } elseif (! $outgoing && $sourceType === 'internal' && $sourceDirectoryType === 'staff' && is_numeric($this->input('source_staff_user_id'))) {
            $staff = User::query()->where('active', true)->where('locked', false)->find((int) $this->input('source_staff_user_id'));
            $senderName = $staff === null ? '' : $this->staffLabel($staff);
        } elseif (! $outgoing && $sourceType === 'external') {
            $senderName = $externalSource ?? '';
        }
        $destinationType = trim((string) $this->input('destination_type'));
        if ($destinationType === '' && $this->filled('recipient_name')) {
            // Preserve existing integrations and imported forms. The capture
            // UI always submits the destination type explicitly.
            $destinationType = 'external';
        }
        $destinationDirectoryType = trim((string) $this->input('destination_directory_type'));
        if ($destinationType === 'internal' && $destinationDirectoryType === '') {
            $destinationDirectoryType = is_numeric($this->input('recipient_staff_user_id')) ? 'staff' : 'shorthand';
        }
        $recipientName = trim((string) $this->input('recipient_name'));
        if ($destinationType === 'internal' && $destinationDirectoryType === 'shorthand' && is_numeric($this->input('recipient_annotation_title_id'))) {
            $title = AnnotationTitle::query()
                ->where('active', true)
                ->find((int) $this->input('recipient_annotation_title_id'));
            $recipientName = $title === null ? '' : "{$title->shorthand} — {$title->full_title}";
        } elseif ($destinationType === 'internal' && $destinationDirectoryType === 'staff' && is_numeric($this->input('recipient_staff_user_id'))) {
            $staff = User::query()->where('active', true)->where('locked', false)->find((int) $this->input('recipient_staff_user_id'));
            $recipientName = $staff === null ? '' : $this->staffLabel($staff);
        }

        $this->merge([
            'direction' => $outgoing ? 'outgoing' : 'incoming',
            'sender_name' => $senderName,
            'source_type' => $sourceType,
            'external_source' => $externalSource,
            'annotation_title_id' => $outgoing ? null : $this->input('annotation_title_id'),
            'source_directory_type' => $outgoing ? null : $sourceDirectoryType,
            'source_staff_user_id' => $outgoing ? null : $this->input('source_staff_user_id'),
            'destination_type' => $destinationType,
            'destination_directory_type' => $destinationDirectoryType,
            'recipient_annotation_title_id' => $this->input('recipient_annotation_title_id'),
            'recipient_staff_user_id' => $this->input('recipient_staff_user_id'),
            'recipient_name' => $recipientName,
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
            'submission_token' => ['nullable', 'uuid', Rule::unique('mail_records', 'submission_token')],
            'sender_name' => ['nullable', Rule::requiredIf(fn () => $this->input('direction') === 'outgoing'), 'string', 'max:255'],
            'sender_organisation' => ['nullable', 'string', 'max:255'],
            'source_type' => ['nullable', Rule::requiredIf(fn () => $this->input('direction') === 'incoming'), Rule::in(['internal', 'external'])],
            'source_directory_type' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('direction') === 'incoming' && $this->input('source_type') === 'internal'),
                Rule::prohibitedIf(fn () => $this->input('source_type') === 'external'),
                Rule::in(['shorthand', 'staff']),
            ],
            'annotation_title_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('direction') === 'incoming' && $this->input('source_type') === 'internal' && $this->input('source_directory_type') === 'shorthand'),
                Rule::prohibitedIf(fn () => $this->input('source_type') === 'external' || $this->input('source_directory_type') === 'staff'),
                'integer',
                Rule::exists('annotation_titles', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'source_staff_user_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('direction') === 'incoming' && $this->input('source_type') === 'internal' && $this->input('source_directory_type') === 'staff'),
                Rule::prohibitedIf(fn () => $this->input('source_type') === 'external' || $this->input('source_directory_type') === 'shorthand'),
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('active', true)->where('locked', false)->whereNull('deleted_at')),
            ],
            'external_source' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('direction') === 'incoming' && $this->input('source_type') === 'external'),
                Rule::prohibitedIf(fn () => $this->input('direction') === 'incoming' && $this->input('source_type') === 'internal'),
                'string',
                'max:255',
            ],
            'destination_type' => ['required', Rule::in(['internal', 'external'])],
            'destination_directory_type' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('destination_type') === 'internal'),
                Rule::prohibitedIf(fn () => $this->input('destination_type') === 'external'),
                Rule::in(['shorthand', 'staff']),
            ],
            'recipient_annotation_title_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('destination_type') === 'internal' && $this->input('destination_directory_type') === 'shorthand'),
                Rule::prohibitedIf(fn () => $this->input('destination_type') === 'external' || $this->input('destination_directory_type') === 'staff'),
                'integer',
                Rule::exists('annotation_titles', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'recipient_staff_user_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('destination_type') === 'internal' && $this->input('destination_directory_type') === 'staff'),
                Rule::prohibitedIf(fn () => $this->input('destination_type') === 'external' || $this->input('destination_directory_type') === 'shorthand'),
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('active', true)->where('locked', false)->whereNull('deleted_at')),
            ],
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
            $duplicate = app(MailDuplicateService::class)->strongest($this->user(), [
                'subject' => $this->input('subject'),
                'sender_name' => $this->input('sender_name'),
                'recipient_name' => $this->input('recipient_name'),
                'correspondence_reference' => $this->input('correspondence_reference'),
                'mail_date' => $this->input($dateColumn),
            ]);

            if ($duplicate !== null && $duplicate['match_strength'] >= 3) {
                $validator->errors()->add(
                    'duplicate_override',
                    "Strong possible duplicate of {$duplicate['register_number']}. Confirm below only if this is a separate mail record."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'submission_token.unique' => 'This form has already been submitted. No duplicate record was created.',
            'source_type.required' => 'Choose an internal reusable source or a custom external source.',
            'annotation_title_id.required' => 'Select an internal source from the shared directory.',
            'annotation_title_id.prohibited' => 'An external source cannot also be linked to the internal directory.',
            'annotation_title_id.exists' => 'Select an active internal source from the shared directory.',
            'source_staff_user_id.required' => 'Select an internal staff member from the staff directory.',
            'source_staff_user_id.exists' => 'Select an active staff member from the staff directory.',
            'external_source.required' => 'Enter the custom external source.',
            'external_source.prohibited' => 'An internal source cannot also contain custom external source text.',
            'destination_type.required' => 'Choose a shared-directory destination or enter an individual or external destination.',
            'recipient_annotation_title_id.required' => 'Select a destination from the shared shorthand directory.',
            'recipient_annotation_title_id.prohibited' => 'A custom destination cannot also be linked to the shared shorthand directory.',
            'recipient_annotation_title_id.exists' => 'Select an active destination from the shared shorthand directory.',
            'recipient_staff_user_id.required' => 'Select an internal destination from the staff directory.',
            'recipient_staff_user_id.exists' => 'Select an active destination from the staff directory.',
            'duplicate_reason.required' => 'Please briefly explain why this mail is not a duplicate before saving.',
            'duplicate_reason.required_if' => 'Please briefly explain why this mail is not a duplicate before saving.',
        ];
    }

    private function nullableTrimmedString(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }

    private function staffLabel(User $user): string
    {
        $title = trim((string) $user->title);

        return $title === '' ? $user->full_name : "{$user->full_name} — {$title}";
    }
}
