<?php

namespace App\Services\Mail;

use App\Models\MailRecord;
use App\Models\User;

class BasicActionRecipientResolver
{
    public function resolve(MailRecord $mail, User $actor): ?int
    {
        $mail->loadMissing('correspondence.recipients', 'task', 'routingTask');

        $candidates = $mail->correspondence?->recipients
            ?->where('active', true)
            ->where('recipient_type', 'to')
            ->whereNotNull('user_id')
            ->sortByDesc('id')
            ->pluck('user_id')
            ->all() ?? [];

        $candidates = array_values(array_unique(array_filter([
            ...$candidates,
            $mail->routingTask?->assigned_to_user_id,
            $mail->task?->assigned_to_user_id,
            $mail->recipient_staff_user_id,
            $mail->office_supervisor_user_id,
        ])));

        foreach ($candidates as $id) {
            if (app(RecipientSearchService::class)->assignableUsers($actor)->whereKey($id)->exists()) {
                return (int) $id;
            }
        }

        return null;
    }
}
