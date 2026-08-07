<?php

namespace App\Http\Requests\Mail;

use App\Models\MailRecord;
use App\Services\Mail\MailFeatureSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIncomingMailRequest extends FormRequest
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
        ]);
    }

    public function authorize(): bool
    {
        /** @var MailRecord $mail */
        $mail = $this->route('mail');

        return $mail->isIncoming() && $this->user()->can('update', $mail);
    }

    public function rules(): array
    {
        return [
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_organisation' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:500'],
            'details' => ['nullable', 'string', 'max:10000'],
            'correspondence_reference' => ['nullable', 'string', 'max:255'],
            'letter_date' => ['nullable', 'date'],
            'received_date' => ['required', 'date'],
            'receipt_method' => ['nullable', Rule::in(['hand', 'courier', 'email', 'post', 'other'])],
            'confidentiality' => ['required', Rule::in(['normal', 'confidential', 'restricted'])],
            'registry_file_number' => ['nullable', 'string', 'max:255'],
        ];
    }
}
