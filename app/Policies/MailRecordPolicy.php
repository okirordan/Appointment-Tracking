<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\DepartmentAccessService;
use App\Services\SecretaryAuthorityService;
use App\Services\SecretaryOfficeScope;
use App\Services\Tasks\AssignmentTargetService;

class MailRecordPolicy
{
    public function __construct(
        private SecretaryOfficeScope $secretaryOffices,
        private SecretaryAuthorityService $secretaryAuthority,
        private DepartmentAccessService $departments,
        private AssignmentTargetService $targets,
    ) {}

    /**
     * Roles with built-in registry access. Original correspondence is an
     * explicitly authorised capability: receiving a delegated assignment
     * does NOT grant access to its source correspondence (CORR-ACCESS).
     */
    private const REGISTRY_ROLES = [Role::Ps, Role::Clerk, Role::Commissioner, Role::Secretary];

    public function viewAny(User $user): bool
    {
        return config('ats.mail.enabled', true)
            && $user->role !== Role::Sysadmin
            && ($user->can('mail.view') || in_array($user->role, self::REGISTRY_ROLES, true));
    }

    /**
     * Registry users retain their office/department scope. Explicit active
     * correspondence recipients and access-grant holders can also open the
     * one record they participate in, without gaining register-wide access.
     */
    public function view(User $user, MailRecord $mail): bool
    {
        if ($mail->correspondence_id !== null) {
            $officeIds = $this->targets->officeIdsFor($user);
            $departmentIds = $this->targets->departmentIdsFor($user);
            $participant = $mail->correspondence?->recipients()
                ->where('active', true)
                ->where(function ($recipient) use ($user, $officeIds, $departmentIds) {
                    $recipient->where('user_id', $user->id);
                    if ($officeIds !== []) {
                        $recipient->orWhereIn('organizational_unit_id', $officeIds);
                    }
                    if ($departmentIds !== []) {
                        $recipient->orWhereIn('department_id', $departmentIds);
                    }
                })
                ->exists();
            $granted = $mail->correspondence?->accessGrants()
                ->where('user_id', $user->id)->whereNull('revoked_at')->exists();
            if ($participant || $granted) {
                return true;
            }
        }

        if (! $this->viewAny($user)) {
            return false;
        }
        if ($user->role === Role::Secretary) {
            return $this->secretaryOffices->allowsMail($user, $mail);
        }
        if ($user->role === Role::Commissioner) {
            return $this->departments->allowsMail($user, $mail);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return config('ats.mail.enabled', true)
            && $user->role !== Role::Sysadmin
            && ($user->can('mail.manage')
                || $this->secretaryAuthority->allows($user, 'mail.manage')
                || in_array($user->role, [Role::Ps, Role::Clerk], true));
    }

    public function update(User $user, MailRecord $mail): bool
    {
        return $this->create($user)
            && ($user->role !== Role::Secretary || $this->secretaryOffices->allowsMail($user, $mail));
    }

    public function participate(User $user, MailRecord $mail): bool
    {
        if (! $this->view($user, $mail)) {
            return false;
        }

        return ! in_array($mail->correspondence?->current_status?->value, ['closed', 'withdrawn'], true);
    }

    public function assign(User $user, MailRecord $mail): bool
    {
        $allowed = config('ats.mail.enabled', true)
            && $user->role !== Role::Sysadmin
            && ($user->can('mail.assign')
                || $this->secretaryAuthority->allows($user, 'mail.assign')
                || in_array($user->role, [Role::Ps, Role::Clerk, Role::Commissioner], true))
            && $mail->isIncoming()
            && ! in_array($mail->correspondence?->current_status?->value, ['closed', 'withdrawn'], true);

        return $allowed
            && match ($user->role) {
                Role::Secretary => $this->secretaryOffices->allowsMail($user, $mail),
                Role::Commissioner => $this->departments->allowsMail($user, $mail),
                default => true,
            };
    }
}
