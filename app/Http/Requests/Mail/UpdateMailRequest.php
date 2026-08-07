<?php

namespace App\Http\Requests\Mail;

use App\Models\MailRecord;
use App\Services\Mail\MailFeatureSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMailRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        /** @var MailRecord $mail */
        $mail = $this->route('mail');
        $features = app(MailFeatureSettings::class);
        $this->merge([
            'correspondence_reference' => $features->enabled('correspondence_reference') ? $this->input('correspondence_reference') : $mail->correspondence_reference,
            'receipt_method' => $features->enabled('receipt_method') ? $this->input('receipt_method') : $mail->receipt_method,
            'confidentiality' => $features->enabled('confidentiality') ? $this->input('confidentiality') : $mail->confidentiality,
            'registry_file_number' => $features->enabled('registry_file_number') ? $this->input('registry_file_number') : $mail->registry_file_number,
            'priority' => $features->enabled('priority') ? $this->input('priority') : $mail->priority->value,
        ]);
    }

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
