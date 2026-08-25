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
        $lifecycle = $mail->correspondence?->current_status;
        $blockedLifecycle = $lifecycle === CorrespondenceLifecycleStatus::Closed
            || ($lifecycle === CorrespondenceLifecycleStatus::Withdrawn
                && ! $this->isRecoverableRecipientWithdrawal($mail));
        $allowed = config('ats.mail.enabled', true)
            && $user->role !== Role::Sysadmin
            && ($user->can('mail.assign')
                || $this->secretaryAuthority->allows($user, 'mail.assign')
                || in_array($user->role, [Role::Ps, Role::Clerk, Role::Commissioner], true))
            && $mail->isIncoming()
            && ! $blockedLifecycle;

        return $allowed && $this->access->allows($user, $mail);
    }

    /**
     * Filing keeps the full correspondence and assignment history while
     * removing the item from active work. In addition to active incoming
     * mail, a released outgoing assignment may be filed after withdrawal.
     */
    public function file(User $user, MailRecord $mail): bool
    {
        $lifecycle = $mail->correspondence?->current_status;

        if ($mail->isIncoming()) {
            if (! $this->assign($user, $mail)) {
                return false;
            }

            return $lifecycle === null
                ? in_array($mail->status->value, ['received', 'registered', 'awaiting_review'], true)
                : ($lifecycle->isActiveIncoming() || $this->isRecoverableRecipientWithdrawal($mail));
        }

        return $mail->direction === 'outgoing'
            && $this->canRecoverWithdrawnOutgoing($user, $mail)
            && ! $this->hasActiveActionAssignment($mail)
            && in_array($lifecycle, [CorrespondenceLifecycleStatus::Incoming, CorrespondenceLifecycleStatus::Forwarded], true)
            && $this->access->allows($user, $mail);
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
        if ((! $this->createOutgoingAssignment($user) && ! $this->canRecoverWithdrawnOutgoing($user, $mail))
            || $mail->direction !== 'outgoing'
            || $this->hasActiveActionAssignment($mail)
            || in_array($mail->correspondence?->current_status?->value, ['closed', 'withdrawn'], true)) {
            return false;
        }

        return $this->access->allows($user, $mail);
    }

    private function canRecoverWithdrawnOutgoing(User $user, MailRecord $mail): bool
    {
        if (! config('ats.mail.enabled', true) || $user->role === Role::Sysadmin) {
            return false;
        }

        $hasAuthority = $user->can('mail.assign')
            || $this->secretaryAuthority->allows($user, 'mail.assign')
            || in_array($user->role, [Role::Ps, Role::Clerk, Role::Commissioner], true);

        return $hasAuthority && $this->hasWithdrawnAssignment($mail);
    }

    private function hasActiveActionAssignment(MailRecord $mail): bool
    {
        return $mail->task_id !== null
            || (bool) $mail->correspondence?->recipients()
                ->where('active', true)
                ->where('purpose', 'action_required')
                ->whereNotNull('task_id')
                ->exists();
    }

    private function hasWithdrawnAssignment(MailRecord $mail): bool
    {
        if ($mail->routingTask?->execution_status === 'unassigned') {
            return true;
        }

        return (bool) $mail->correspondence?->recipients()
            ->where('active', false)
            ->whereNotNull('task_id')
            ->whereHas('task', fn ($task) => $task->where('execution_status', 'unassigned'))
            ->exists();
    }

    private function isRecoverableRecipientWithdrawal(MailRecord $mail): bool
    {
        return $mail->correspondence?->current_status === CorrespondenceLifecycleStatus::Withdrawn
            && $mail->task_id === null
            && ! (bool) $mail->correspondence?->recipients()->where('active', true)->exists();
    }
}
