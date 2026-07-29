<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case Received = 'received';
    case InProgress = 'in_progress';
    case Pending = 'pending';
    case AwaitingReview = 'awaiting_review';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Assigned => 'Assigned',
            self::Received => 'Received',
            self::InProgress => 'In Progress',
            self::Pending => 'Pending',
            self::AwaitingReview => 'Awaiting Review',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /** Badge class from the prototype's exact status→style map. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Created, self::Assigned => 'st-assigned',
            self::Received => 'st-received',
            self::InProgress => 'st-inprogress',
            self::Pending => 'st-pending',
            self::AwaitingReview => 'st-awaitingreview',
            self::Completed => 'st-completed',
            self::Archived => 'st-archived',
        };
    }

    /** Suggested automatic progress per PRD §12.10. */
    public function suggestedProgress(): int
    {
        return match ($this) {
            self::Created, self::Assigned, self::Received => 0,
            self::InProgress => 25,
            self::Pending => 50,
            self::AwaitingReview => 75,
            self::Completed, self::Archived => 100,
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Archived;
    }

    /** Statuses an assignee may select when updating progress. */
    public static function selectableForUpdate(): array
    {
        return [self::Received, self::InProgress, self::Pending, self::AwaitingReview, self::Completed];
    }
}
