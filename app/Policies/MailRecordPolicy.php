<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\DepartmentAccessService;
use App\Services\SecretaryAuthorityService;
use App\Services\SecretaryOfficeScope;

class MailRecordPolicy
{
    public function __construct(
        private SecretaryOfficeScope $secretaryOffices,
        private SecretaryAuthorityService $secretaryAuthority,
        private DepartmentAccessService $departments,
    ) {}

    /**
     * Roles with built-in registry access. Original correspondence is an
     * explicitly authorised capability: receiving a delegated assignment
     * does NOT grant access to its source correspondence (CORR-ACCESS).
     */
    private const REGISTRY_ROLES = [Role::Sysadmin, Role::Ps, Role::Clerk, Role::Commissioner, Role::Secretary];

    public function viewAny(User $user): bool
    {
        return config('ats.mail.enabled', true)
            && ($user->can('mail.view') || in_array($user->role, self::REGISTRY_ROLES, true));
    }

    /**
     * Viewing a specific correspondence record (and its attached original
     * documents) requires the same explicit registry authorisation. Task
     * visibility deliberately does not carry over — a Commissioner or any
     * delegated user must be granted the `mail.view` permission before the
     * original correspondence becomes accessible.
     */
    public function view(User $user, MailRecord $mail): bool
    {
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
            && ($user->can('mail.manage')
                || $this->secretaryAuthority->allows($user, 'mail.manage')
                || in_array($user->role, [Role::Sysadmin, Role::Ps, Role::Clerk], true));
    }

    public function update(User $user, MailRecord $mail): bool
    {
        return $this->create($user)
            && ($user->role !== Role::Secretary || $this->secretaryOffices->allowsMail($user, $mail));
    }

    public function assign(User $user, MailRecord $mail): bool
    {
        $allowed = config('ats.mail.enabled', true)
            && ($user->can('mail.assign')
                || $this->secretaryAuthority->allows($user, 'mail.assign')
                || in_array($user->role, [Role::Sysadmin, Role::Ps, Role::Clerk, Role::Commissioner], true))
            && $mail->isIncoming() && $mail->task_id === null;

        return $allowed
            && match ($user->role) {
                Role::Secretary => $this->secretaryOffices->allowsMail($user, $mail),
                Role::Commissioner => $this->departments->allowsMail($user, $mail),
                default => true,
            };
    }
}
