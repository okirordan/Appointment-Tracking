<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\SecretaryOfficeAttachment;
use App\Models\User;

class SecretaryAuthorityService
{
    private const DEPARTMENT_SECRETARY_PERMISSIONS = [
        'assignments.create',
        'mail.manage',
        'mail.assign',
        'mail.view.sensitive',
    ];

    /** @return array<string, string> */
    public function availablePermissions(): array
    {
        return [
            'assignments.create' => 'Prepare and issue assignments',
            'assignments.delegate' => 'Delegate an active assignment',
            'assignments.direct' => 'Use direct assignment routing',
            'assignments.review' => 'Review submissions',
            'assignments.return' => 'Return work for correction',
            'assignments.approve' => 'Approve submissions',
            'assignments.reject' => 'Reject submissions',
            'assignments.reassign' => 'Reassign an active workflow step',
            'mail.manage' => 'Capture and update correspondence',
            'mail.assign' => 'Convert incoming mail to an assignment',
            'mail.view.sensitive' => 'View confidential and restricted correspondence',
            'reports.export' => 'Export office reports',
        ];
    }

    public function attachment(User $user): ?SecretaryOfficeAttachment
    {
        $attachment = $user->currentSecretaryAttachment()
            ->with(['supervisor', 'organizationalUnit'])
            ->first();

        return $attachment instanceof SecretaryOfficeAttachment ? $attachment : null;
    }

    public function allows(User $user, string $permission): bool
    {
        $attachment = $this->attachment($user);
        if ($attachment === null) {
            return $user->role === Role::Secretary
                && $user->department_id !== null
                && in_array($permission, self::DEPARTMENT_SECRETARY_PERMISSIONS, true);
        }

        if ($attachment->supervisor->role === Role::Ps
            && in_array($permission, ['mail.manage', 'mail.assign', 'mail.view.sensitive'], true)) {
            return true;
        }

        $departmentId = $attachment->organizationalUnit?->department_id
            ?? $attachment->supervisor?->department_id
            ?? $user->department_id;
        if ($user->role === Role::Secretary
            && $departmentId !== null
            && in_array($permission, self::DEPARTMENT_SECRETARY_PERMISSIONS, true)) {
            return true;
        }

        if (! $attachment->delegated_actions_permitted) {
            return false;
        }

        return in_array($permission, $attachment->delegated_permissions ?? [], true);
    }
}
