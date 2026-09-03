<?php

namespace App\Services\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\OrganizationalScopeService;
use Illuminate\Database\Eloquent\Builder;

class MailboxScope
{
    public function __construct(private OrganizationalScopeService $organizations) {}

    /** @param Builder<MailRecord> $query */
    public function incoming(Builder $query, User $user): Builder
    {
        if ($user->role === Role::Ps) {
            return $this->legacyIncoming($query);
        }

        $unitIds = $this->organizations->unitIds($user);
        $departmentIds = $this->organizations->recipientDepartmentIds($user);

        return $query->where(function (Builder $incoming) use ($user, $unitIds, $departmentIds) {
            $incoming
                ->where(function (Builder $owned) use ($user, $unitIds, $departmentIds) {
                    $owned->where('mail_records.direction', 'incoming')
                        ->where(fn (Builder $owner) => $this->applyOwnership($owner, $user, $unitIds, $departmentIds))
                        ->where(fn (Builder $notSent) => $this->withoutForwardFromScope($notSent, $user, $unitIds, $departmentIds));
                })
                ->orWhere(function (Builder $received) use ($user, $unitIds, $departmentIds) {
                    $received
                        ->whereHas('correspondence.recipients', fn (Builder $recipient) => $this->applyRecipient($recipient, $user, $unitIds, $departmentIds))
                        ->where(fn (Builder $notSent) => $this->withoutForwardFromScope($notSent, $user, $unitIds, $departmentIds));
                });
        })->where(function (Builder $notFiled) {
            $notFiled->whereNull('correspondence_id')
                ->orWhereHas('correspondence', fn (Builder $correspondence) => $correspondence
                    ->where('current_status', '!=', CorrespondenceLifecycleStatus::Filed->value));
        });
    }

    /** @param Builder<MailRecord> $query */
    public function outgoing(Builder $query, User $user): Builder
    {
        if ($user->role === Role::Ps) {
            return $this->legacyOutgoing($query);
        }

        $unitIds = $this->organizations->unitIds($user);
        $departmentIds = $this->organizations->recipientDepartmentIds($user);

        return $query->where(function (Builder $outgoing) use ($user, $unitIds, $departmentIds) {
            $outgoing
                ->where(function (Builder $registered) use ($user, $unitIds, $departmentIds) {
                    $registered->where('mail_records.direction', 'outgoing')
                        ->whereNull('source_mail_record_id')
                        ->where(fn (Builder $owner) => $this->applyOwnership($owner, $user, $unitIds, $departmentIds));
                })
                ->orWhereHas('correspondence.forwards', function (Builder $forward) use ($user, $unitIds, $departmentIds) {
                    $forward->where('status', 'sent')->where(function (Builder $sender) use ($user, $unitIds, $departmentIds) {
                        $sender->where('forwarded_by_user_id', $user->id)
                            ->orWhere('on_behalf_of_user_id', $user->id);
                        if ($unitIds !== []) {
                            $sender->orWhereIn('from_organizational_unit_id', $unitIds);
                        }
                        if ($departmentIds !== []) {
                            $sender->orWhereHas('fromOrganizationalUnit', fn (Builder $unit) => $unit
                                ->whereIn('department_id', $departmentIds));
                        }
                    });
                });
        });
    }

    /** @param Builder<MailRecord> $query */
    private function legacyIncoming(Builder $query): Builder
    {
        return $query->where('mail_records.direction', 'incoming')
            ->where(function (Builder $active) {
                $active->whereHas('correspondence', fn (Builder $correspondence) => $correspondence
                    ->whereIn('current_status', ['incoming', 'under_review']))
                    ->orWhere(fn (Builder $legacy) => $legacy
                        ->whereNull('correspondence_id')
                        ->whereIn('status', ['received', 'registered', 'awaiting_review']));
            });
    }

    /** @param Builder<MailRecord> $query */
    private function legacyOutgoing(Builder $query): Builder
    {
        return $query->where(function (Builder $outgoing) {
            $outgoing->where(fn (Builder $registered) => $registered
                ->where('mail_records.direction', 'outgoing')->whereNull('source_mail_record_id'))
                ->orWhere(fn (Builder $forwarded) => $forwarded
                    ->where('mail_records.direction', 'incoming')
                    ->whereHas('correspondence', fn (Builder $correspondence) => $correspondence
                        ->whereNotIn('current_status', ['incoming', 'under_review', 'filed'])));
        });
    }

    /** @param Builder<MailRecord> $query */
    private function applyOwnership(Builder $query, User $user, array $unitIds, array $departmentIds): void
    {
        $query->whereRaw('1 = 0');
        if ($unitIds !== []) {
            $query->orWhereIn('mail_records.organizational_unit_id', $unitIds);
        }
        if ($departmentIds !== []) {
            $query->orWhereIn('mail_records.department_id', $departmentIds);
        }
        if ($user->role === Role::Clerk) {
            $query->orWhere('mail_records.captured_by_user_id', $user->id);
        }
    }

    private function applyRecipient(Builder $query, User $user, array $unitIds, array $departmentIds): void
    {
        $query->where('active', true)->where(function (Builder $target) use ($user, $unitIds, $departmentIds) {
            $target->where('user_id', $user->id);
            if ($unitIds !== []) {
                $target->orWhereIn('organizational_unit_id', $unitIds);
            }
            if ($departmentIds !== []) {
                $target->orWhereIn('department_id', $departmentIds);
            }
        });
    }

    /** @param Builder<MailRecord> $query */
    private function withoutForwardFromScope(Builder $query, User $user, array $unitIds, array $departmentIds): void
    {
        $query->whereDoesntHave('correspondence.forwards', function (Builder $forward) use ($user, $unitIds, $departmentIds) {
            $forward->where('status', 'sent')->where(function (Builder $sender) use ($user, $unitIds, $departmentIds) {
                $sender->where('forwarded_by_user_id', $user->id)
                    ->orWhere('on_behalf_of_user_id', $user->id);
                if ($unitIds !== []) {
                    $sender->orWhereIn('from_organizational_unit_id', $unitIds);
                }
                if ($departmentIds !== []) {
                    $sender->orWhereHas('fromOrganizationalUnit', fn (Builder $unit) => $unit
                        ->whereIn('department_id', $departmentIds));
                }
            });
        });
    }
}
