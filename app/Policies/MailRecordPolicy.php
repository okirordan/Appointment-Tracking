<?php

namespace App\Policies;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\Mail\MailAccessScope;
use App\Services\SecretaryAuthorityService;

class MailRecordPolicy
{
    public function __construct(
        private SecretaryAuthorityService $secretaryAuthority,
        private MailAccessScope $access,
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
     * Confidentiality policy: the PS may oversee all correspondence; only a
     * department head and secretary inherit their department register. Other
     * officers can open only records directly forwarded, assigned, shared,
     * or explicitly granted to them through the correspondence workflow.
     */
    public function view(User $user, MailRecord $mail): bool
    {
        return config('ats.mail.enabled', true)
            && $this->access->allows($user, $mail);
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
        if (! $this->create($user)) {
            return false;
        }

        return $this->access->allows($user, $mail);
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

        return $allowed && $this->access->allows($user, $mail);
    }

    /**
     * Filing keeps a correspondence within the receiving office without
     * creating an assignment. It uses the same authority as forwarding and
     * only applies to items still in the active incoming queue.
     */
    public function file(User $user, MailRecord $mail): bool
    {
        if (! $this->assign($user, $mail)) {
            return false;
        }

        $lifecycle = $mail->correspondence?->current_status;

        return $lifecycle === null
            ? in_array($mail->status->value, ['received', 'registered', 'awaiting_review'], true)
            : $lifecycle->isActiveIncoming();
    }

    public function reopen(User $user, MailRecord $mail): bool
    {
        return $this->assign($user, $mail)
            && $mail->correspondence?->current_status === CorrespondenceLifecycleStatus::Filed;
    }

    public function createOutgoingAssignment(User $user): bool
    {
        if (! config('ats.mail.enabled', true) || in_array($user->role, [Role::Sysadmin, Role::Clerk], true)) {
            return false;
        }

        return in_array($user->role, [Role::Ps, Role::Commissioner], true)
            || $this->secretaryAuthority->allows($user, 'mail.assign')
            || $user->can('mail.assign');
    }

    public function assignOutgoing(User $user, MailRecord $mail): bool
    {
        if (! $this->createOutgoingAssignment($user)
            || $mail->direction !== 'outgoing'
            || $mail->task_id !== null
            || $mail->correspondence?->recipients()->whereNotNull('task_id')->exists()
            || in_array($mail->correspondence?->current_status?->value, ['closed', 'withdrawn'], true)) {
            return false;
        }

        return $this->access->allows($user, $mail);
    }
}
