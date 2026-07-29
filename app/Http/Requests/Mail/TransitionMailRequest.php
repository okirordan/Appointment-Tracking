<?php

namespace App\Http\Requests\Mail;

use App\Enums\CorrespondenceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('mail'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(CorrespondenceStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'dispatch_method' => ['nullable', 'string', 'max:50'],
            'dispatch_reference' => ['nullable', 'string', 'max:255'],
            'dispatched_at' => ['nullable', 'date'],
        ];
    }
}
