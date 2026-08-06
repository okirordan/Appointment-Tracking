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
        return [
            'type' => ['required', Rule::in(['note', 'annotation', 'progress', 'response', 'clarification', 'recommendation', 'decision'])],
            'body' => ['required', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,mp4,webm'],
        ];
    }
}
