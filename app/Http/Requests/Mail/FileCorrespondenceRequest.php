<?php

namespace App\Http\Requests\Mail;

use App\Models\MailRecord;
use Illuminate\Foundation\Http\FormRequest;

class FileCorrespondenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mail = $this->route('mail');

        return $mail instanceof MailRecord && $this->user()->can('file', $mail);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'filing_category' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
