<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;
use App\Models\UserDelegation;
use App\Services\AuditLogger;
use App\Services\SecretaryAttachmentService;
use App\Services\SecretaryAuthorityService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Compatibility endpoints for temporary delegations and dated secretary
 * attachments. Organizational entities themselves are managed exclusively
 * by OrganizationStructureController.
 */
class HierarchyController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private SecretaryAttachmentService $secretaryAttachments,
        private SecretaryAuthorityService $secretaryAuthority,
    ) {}

    public function storeDelegation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'delegator_user_id' => ['required', 'integer', 'exists:users,id'],
            'delegate_user_id' => ['required', 'integer', 'exists:users,id', 'different:delegator_user_id'],
            'organizational_unit_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $delegation = UserDelegation::create([
            ...$data,
            'active' => true,
            'created_by_user_id' => $request->user()->id,
        ]);
        $this->audit->log(
            'hierarchy',
            'Created temporary delegation arrangement',
            $request->user(),
            'UserDelegation',
            $delegation->id,
            $data,
        );

        return back()->with('success', 'Temporary delegation is active for the selected period.');
    }

    public function assignSecretary(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'secretary_user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true)],
            'supervisor_user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('active', true), 'different:secretary_user_id'],
            'organizational_unit_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')->whereNull('deleted_at')->where('active', true)],
            'official_job_title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'before_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'delegated_actions_permitted' => ['sometimes', 'boolean'],
            'delegated_permissions' => ['nullable', 'array'],
            'delegated_permissions.*' => ['string', Rule::in(array_keys($this->secretaryAuthority->availablePermissions()))],
            'move_existing_correspondence' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->secretaryAttachments->assign(
            User::findOrFail($data['secretary_user_id']),
            User::findOrFail($data['supervisor_user_id']),
            empty($data['organizational_unit_id']) ? null : OrganizationalUnit::findOrFail($data['organizational_unit_id']),
            $data['official_job_title'],
            Carbon::parse($data['starts_at']),
            empty($data['ends_at']) ? null : Carbon::parse($data['ends_at']),
            (bool) ($data['delegated_actions_permitted'] ?? false),
            $data['delegated_permissions'] ?? [],
            $request->user(),
            $data['reason'],
            (bool) ($data['move_existing_correspondence'] ?? false),
        );

        return back()->with('success', 'Secretary office attachment, dashboard scope and delegated authority updated.');
    }

    public function endSecretaryAttachment(Request $request, SecretaryOfficeAttachment $attachment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $this->secretaryAttachments->end($attachment, $request->user(), $data['reason']);

        return back()->with('success', 'Secretary office access removed. Existing correspondence and assignment records were preserved.');
    }
}
