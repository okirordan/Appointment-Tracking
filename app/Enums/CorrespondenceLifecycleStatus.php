<?php

namespace App\Enums;

enum CorrespondenceLifecycleStatus: string
{
    case Incoming = 'incoming';
    case UnderReview = 'under_review';
    case Forwarded = 'forwarded';
    case ActionRequired = 'action_required';
    case AwaitingResponse = 'awaiting_response';
    case Responded = 'responded';
    case Closed = 'closed';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Forwarded => 'Outgoing / Forwarded',
            default => str($this->value)->replace('_', ' ')->title()->toString(),
        };
    }

    public function isActiveIncoming(): bool
    {
        return in_array($this, [self::Incoming, self::UnderReview], true);
    }
}
