<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateProgress', $this->route('task'));
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $evidence = config('ats.evidence');

        return [
            'status' => ['required', Rule::in(array_map(
                fn (TaskStatus $status) => $status->value,
                TaskStatus::selectableForUpdate(),
            ))],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            // PROG-002: every progress update requires a note.
            'note' => ['required', 'string', 'max:5000'],
            'evidence' => ['nullable', 'array', 'max:'.$evidence['max_items_per_update']],
            'evidence.*' => [
                'file',
                'max:'.$evidence['max_size_kb'],
                'mimes:'.implode(',', $evidence['allowed_extensions']),
                'mimetypes:'.implode(',', $evidence['allowed_mimes']),
            ],
            'evidence_links' => ['nullable', 'array', 'max:'.$evidence['max_items_per_update']],
            'evidence_links.*' => ['nullable', 'url:http,https', 'max:2048', 'distinct:ignore_case'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $files = count($this->file('evidence', []));
            $links = count(array_filter(
                $this->input('evidence_links', []),
                fn ($link) => is_string($link) && trim($link) !== '',
            ));

            if ($files + $links > (int) config('ats.evidence.max_items_per_update', 5)) {
                $validator->errors()->add('evidence', 'Attach no more than five files and links in one update.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'note.required' => 'A progress note is required.',
            'evidence.*.mimes' => 'Allowed file types: PDF, Office documents, images, MP4, WebM and MOV video.',
            'evidence.*.mimetypes' => 'Allowed file types: PDF, Office documents, images, MP4, WebM and MOV video.',
            'evidence_links.*.url' => 'Evidence links must be valid HTTP or HTTPS addresses.',
        ];
    }
}
