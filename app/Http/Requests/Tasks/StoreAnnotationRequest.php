<?php

namespace App\Http\Requests\Tasks;

use App\Services\Tasks\AssignmentTargetService;
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
            'origin_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'recipient_user_ids' => ['nullable', 'array', 'max:50'],
            'recipient_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $ids = $this->input('recipient_user_ids', []);
            $users = app(AssignmentTargetService::class)->eligibleUsers()->whereKey($ids)->get();
            if ($users->count() !== count($ids) || $users->contains(fn ($user) => ! $user->can('view', $this->route('task')))) {
                $validator->errors()->add('recipient_user_ids', 'Select officers who already have access to this assignment. Use delegation or reassignment to involve another officer.');
            }
        }];
    }
}
