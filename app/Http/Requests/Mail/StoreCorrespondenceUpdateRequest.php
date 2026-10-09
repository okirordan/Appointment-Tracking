<?php

namespace App\Http\Requests\Mail;

use App\Models\MailRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCorrespondenceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var MailRecord $mail */
        $mail = $this->route('mail');

        return $this->user()->can('participate', $mail);
    }

    public function rules(): array
    {
        $basic = $this->input('entry_method') === 'basic_correspondence';

        return [
            'entry_method' => ['sometimes', Rule::in(['normal', 'basic_correspondence'])],
            'type' => ['required', Rule::in($basic ? ['note'] : ['note', 'annotation', 'progress', 'response', 'clarification', 'recommendation', 'decision'])],
            'body' => ['required', 'string', 'max:10000'],
            'destination_key' => [$basic ? 'nullable' : 'prohibited', 'string', 'max:80'],
            'destination_office_snapshot' => [$basic ? 'required' : 'prohibited', 'string', 'max:255'],
            'destination_kind' => [$basic ? 'nullable' : 'prohibited', Rule::in(['individual', 'organization', 'office'])],
            'additional_destinations' => [$basic ? 'sometimes' : 'prohibited', 'array', 'max:19'],
            'additional_destinations.*.destination_key' => [$basic ? 'nullable' : 'prohibited', 'string', 'max:80'],
            'additional_destinations.*.destination_office_snapshot' => [$basic ? 'required' : 'prohibited', 'string', 'max:255'],
            'additional_destinations.*.destination_kind' => [$basic ? 'nullable' : 'prohibited', Rule::in(['individual', 'organization', 'office'])],
            'recorded_date' => [$basic ? 'required' : 'prohibited', 'date_format:Y-m-d', 'before_or_equal:today'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,mp4,webm'],
        ];
    }
}
