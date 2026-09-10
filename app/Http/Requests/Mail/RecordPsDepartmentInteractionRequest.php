<?php

namespace App\Http\Requests\Mail;

use App\Enums\OrganizationalUnitType;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\Mail\PsOfficeCrossDepartmentAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordPsDepartmentInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var MailRecord $mail */
        $mail = $this->route('mail');

        return app(PsOfficeCrossDepartmentAccess::class)->allows($this->user())
            && $this->user()->can('view', $mail);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'confirm_duplicate' => $this->boolean('confirm_duplicate'),
            'annotation' => $this->filled('annotation') ? trim((string) $this->input('annotation')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['ps_to_department', 'department_to_ps'])],
            'organizational_unit_id' => ['required', 'integer', Rule::exists('organizational_units', 'id')
                ->where(fn ($query) => $query->where('active', true)->whereNull('deleted_at'))],
            'action_type' => ['required', Rule::in([
                'forwarded_for_action', 'returned_to_ps', 'referred_back', 'response_received',
                'forwarded_elsewhere', 'information_requested', 'clarification_requested',
                'action_completed', 'closed', 'filed',
            ])],
            'annotation' => ['nullable', 'string', 'max:10000'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'status_after' => ['required', Rule::in([
                'under_review', 'forwarded', 'action_required', 'awaiting_response',
                'responded', 'closed', 'filed',
            ])],
            'responsible_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')
                ->where(fn ($query) => $query->where('active', true)->where('locked', false)->whereNull('deleted_at'))],
            'due_date' => ['nullable', 'date'],
            'confirm_duplicate' => ['required', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,mp4,webm'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('organizational_unit_id')) {
                return;
            }

            $unit = OrganizationalUnit::query()->find($this->integer('organizational_unit_id'));
            $type = $unit === null ? null : OrganizationalUnitType::tryFrom($unit->type);
            if ($unit === null
                || app(PsOfficeCrossDepartmentAccess::class)->isPsOffice($unit)
                || ! in_array($type, OrganizationalUnitType::selectable(), true)) {
                $validator->errors()->add('organizational_unit_id', 'Select an active internal organizational unit outside the PS Office.');

                return;
            }

            $outboundActions = ['forwarded_for_action', 'forwarded_elsewhere', 'information_requested', 'clarification_requested'];
            $returnActions = ['returned_to_ps', 'referred_back', 'response_received', 'action_completed', 'closed', 'filed'];
            $directionActions = $this->input('direction') === 'ps_to_department' ? $outboundActions : $returnActions;
            if (! in_array($this->input('action_type'), $directionActions, true)) {
                $validator->errors()->add('action_type', 'The selected action does not match the movement direction.');
            }
            if (($this->input('action_type') === 'filed') !== ($this->input('status_after') === 'filed')
                || ($this->input('action_type') === 'closed') !== ($this->input('status_after') === 'closed')) {
                $validator->errors()->add('status_after', 'Filed and closed actions must use their matching final status.');
            }

            if (! $this->filled('responsible_user_id')) {
                if ($this->filled('due_date')) {
                    $validator->errors()->add('due_date', 'Select a responsible officer before setting an assignment due date.');
                }

                return;
            }
            if ($this->input('direction') !== 'ps_to_department' || in_array($this->input('status_after'), ['closed', 'filed'], true)) {
                $validator->errors()->add('responsible_user_id', 'A responsible officer can only be assigned when forwarding active work from the PS Office.');

                return;
            }

            $officer = User::query()->with([
                'organizationalUnit',
                'currentPositionAssignment.position.organizationalUnit',
            ])->find($this->integer('responsible_user_id'));
            $officerUnit = $officer?->currentPositionAssignment?->position?->organizationalUnit
                ?? $officer?->organizationalUnit;
            $belongsToTarget = $officerUnit?->id === $unit->id
                || ($unit->type === OrganizationalUnitType::Department->value
                    && $unit->department_id !== null
                    && $officerUnit?->department_id === $unit->department_id);
            if (! $belongsToTarget) {
                $validator->errors()->add('responsible_user_id', 'The responsible officer must belong to the selected organizational unit.');
            }
            if ($this->filled('due_date')
                && ! $validator->errors()->has('due_date')
                && Carbon::parse((string) $this->input('due_date'))->lt(today())) {
                $validator->errors()->add('due_date', 'The assignment due date must be today or later.');
            }
        });
    }
}
