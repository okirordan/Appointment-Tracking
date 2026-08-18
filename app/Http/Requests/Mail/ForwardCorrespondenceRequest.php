<?php

namespace App\Http\Requests\Mail;

use App\Enums\Priority;
use App\Models\MailRecord;
use App\Services\Mail\MailFeatureSettings;
use App\Services\Mail\RecipientSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ForwardCorrespondenceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $features = app(MailFeatureSettings::class);
        $this->merge([
            'action_required' => $this->has('action_required') ? $this->boolean('action_required') : true,
            'target_type' => $this->input('target_type')
                ?: ($this->filled('organizational_unit_id') ? 'office'
                    : ($this->filled('target_department_id') ? 'department'
                        : (count((array) $this->input('assigned_to_user_ids', [])) > 1 ? 'multiple' : 'individual'))),
            'external_recipients' => $this->input('external_recipients', []),
            'priority' => $features->enabled('priority') ? ($this->input('priority') ?: 'medium') : 'medium',
            'workstream_id' => $features->enabled('project_programme') ? $this->input('workstream_id') : null,
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
            'action_required' => ['required', 'boolean'],
            'forwarded_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'target_type' => ['required', Rule::in(['individual', 'multiple', 'office', 'department'])],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
            'organizational_unit_id' => ['nullable', 'integer', 'required_if:target_type,office', Rule::exists('organizational_units', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
            'target_department_id' => ['nullable', 'integer', 'required_if:target_type,department', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))],
            'assigned_to_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->where('locked', false)->whereNull('deleted_at'))],
            'assigned_to_user_ids' => ['nullable', 'array', 'min:1', 'required_if:target_type,multiple'],
            'assigned_to_user_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->where('locked', false)->whereNull('deleted_at'))],
            'cc_user_ids' => ['nullable', 'array', 'max:50'],
            'cc_user_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->where('locked', false)->whereNull('deleted_at'))],
            'external_recipients' => ['nullable', 'array', 'max:20'],
            'external_recipients.*.name' => ['required', 'string', 'max:255'],
            'external_recipients.*.organisation' => ['nullable', 'string', 'max:255'],
            'external_recipients.*.recipient_type' => ['required', Rule::in(['to', 'cc'])],
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
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('action_required') && $this->filled('due_date')) {
                $validator->errors()->add('due_date', 'A due date can only be set when action is required.');
            }

            $toIds = collect($this->input('assigned_to_user_ids', []))
                ->when($this->filled('assigned_to_user_id'), fn ($ids) => $ids->push($this->integer('assigned_to_user_id')))
                ->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $ccIds = collect($this->input('cc_user_ids', []))->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $hasInternalPrimary = $toIds->isNotEmpty()
                || $this->filled('organizational_unit_id')
                || $this->filled('target_department_id');
            $hasExternalPrimary = collect($this->input('external_recipients', []))
                ->contains(fn ($recipient) => ($recipient['recipient_type'] ?? null) === 'to' && filled($recipient['name'] ?? null));

            if (! $hasInternalPrimary && ! $hasExternalPrimary) {
                $validator->errors()->add('assigned_to_user_ids', 'Select at least one internal or external primary recipient.');
            }
            $externalNames = collect($this->input('external_recipients', []))
                ->map(fn ($recipient) => mb_strtolower(trim((string) ($recipient['name'] ?? ''))))
                ->filter();
            if ($externalNames->duplicates()->isNotEmpty()) {
                $validator->errors()->add('external_recipients', 'Each external recipient can only be added once.');
            }

            if ($toIds->intersect($ccIds)->isNotEmpty()) {
                $validator->errors()->add('cc_user_ids', 'A recipient cannot be both a primary and CC recipient in the same forwarding action.');
            }

            $ids = $toIds->merge($ccIds)->unique()->values();
            if ($ids->isEmpty() || $validator->errors()->has('assigned_to_user_id') || $validator->errors()->has('assigned_to_user_ids')) {
                return;
            }

            $allowedIds = app(RecipientSearchService::class)
                ->assignableUsers($this->user())
                ->whereKey($ids)
                ->pluck('id');
            if ($allowedIds->count() !== $ids->count()) {
                $validator->errors()->add('assigned_to_user_ids', 'One or more recipients are unavailable or outside your authorised forwarding scope.');
            }
        });
    }
}
