<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('annotate', $this->route('task'));
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:5000'],
            'origin_title_id' => ['nullable', 'integer', Rule::exists('annotation_titles', 'id')->where('active', true)],
            'recipient_title_id' => ['nullable', 'integer', Rule::exists('annotation_titles', 'id')->where('active', true)],
        ];
    }
}
