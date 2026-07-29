<?php

namespace App\Http\Requests\Mail;

use App\Models\MailRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('mail'));
    }

    public function rules(): array
    {
        /** @var MailRecord $mail */
        $mail = $this->route('mail');

        return [
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_organisation' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:500'],
            'details' => ['nullable', 'string', 'max:10000'],
            'correspondence_reference' => ['nullable', 'string', 'max:255'],
            'letter_date' => ['nullable', 'date'],
            'received_date' => [$mail->isIncoming() ? 'required' : 'nullable', 'date'],
            'sent_date' => [$mail->isIncoming() ? 'nullable' : 'nullable', 'date'],
            'receipt_method' => ['nullable', Rule::in(['hand', 'courier', 'email', 'post', 'other'])],
            'confidentiality' => ['required', Rule::in(['normal', 'confidential', 'restricted'])],
            'registry_file_number' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
        ];
    }
}
