<?php

namespace App\Enums;

enum CorrespondenceStatus: string
{
    case Received = 'received';
    case Registered = 'registered';
    case Draft = 'draft';
    case AwaitingReview = 'awaiting_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Forwarded = 'forwarded';
    case Assigned = 'assigned';
    case Filed = 'filed';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Received, self::Registered => 'st-received',
            self::Draft => 'muted',
            self::AwaitingReview => 'st-awaitingreview',
            self::Approved, self::Delivered, self::Completed => 'st-completed',
            self::Rejected => 'pr-urgent',
            self::Forwarded, self::Assigned => 'st-assigned',
            self::Dispatched => 'info',
            self::Filed, self::Archived => 'st-archived',
        };
    }

    /** @return list<self> */
    public static function forDirection(string $direction): array
    {
        return $direction === 'incoming'
            ? [self::Received, self::Registered, self::Forwarded, self::Assigned, self::Completed, self::Archived]
            : [self::Draft, self::AwaitingReview, self::Approved, self::Rejected, self::Forwarded, self::Dispatched, self::Delivered, self::Completed, self::Archived];
    }
}
