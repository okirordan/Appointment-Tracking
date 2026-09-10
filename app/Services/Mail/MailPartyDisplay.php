<?php

namespace App\Services\Mail;

use App\Models\AnnotationTitle;
use App\Models\CorrespondenceRecipient;
use App\Models\MailRecord;
use App\Models\User;
use Illuminate\Support\Collection;

class MailPartyDisplay
{
    /** @var Collection<int, AnnotationTitle>|null */
    private ?Collection $officialTitles = null;

    public function __construct(
        private RecipientSearchService $recipients,
        private OrganizationalRoutingLabel $routingLabel,
    ) {}

    public function sender(MailRecord $mail): string
    {
        if ($mail->isIncoming() && $mail->source_type === 'external') {
            return $mail->sender_name;
        }

        return $mail->annotationTitle?->shorthand
            ?? $this->userTitle($mail->sourceStaffUser)
            ?? ($mail->isIncoming() ? null : $this->userTitle($mail->preparedOnBehalfOf))
            ?? ($mail->isIncoming() ? null : $this->officialTitleFor($mail->sender_name))
            ?? $mail->sender_name;
    }

    public function addressee(MailRecord $mail): string
    {
        if ($mail->destination_type === 'external') {
            return $mail->recipient_name;
        }

        return $mail->recipientAnnotationTitle?->shorthand
            ?? $this->userTitle($mail->recipientStaffUser)
            ?? $mail->recipient_name;
    }

    public function recipient(CorrespondenceRecipient $recipient): string
    {
        if ($recipient->target_type === 'external') {
            return $recipient->recipient_name_snapshot;
        }

        if ($recipient->target_type === 'title') {
            return $recipient->forward?->recipientAnnotationTitle?->shorthand
                ?? $recipient->recipient_name_snapshot;
        }

        if ($recipient->user !== null) {
            return $this->userTitle($recipient->user) ?? $recipient->recipient_name_snapshot;
        }

        if ($recipient->organizationalUnit !== null) {
            return $this->routingLabel->for($recipient->organizationalUnit);
        }

        return $recipient->department?->code ?: $recipient->recipient_name_snapshot;
    }

    private function userTitle(?User $user): ?string
    {
        return $user === null ? null : $this->recipients->titleShorthand($user);
    }

    private function officialTitleFor(string $storedName): ?string
    {
        $normalized = AnnotationTitle::normalize($storedName);
        $normalized = preg_replace('/^officeof(?:the)?/', '', $normalized) ?? $normalized;

        if ($normalized === '') {
            return null;
        }

        $matches = $this->titles()->filter(function (AnnotationTitle $title) use ($normalized): bool {
            if ($title->normalized_shorthand === $normalized || $title->normalized_full_title === $normalized) {
                return true;
            }

            return mb_strlen($normalized) >= 8
                && str_starts_with($title->normalized_full_title, $normalized);
        });

        return $matches->count() === 1 ? $matches->first()->shorthand : null;
    }

    /** @return Collection<int, AnnotationTitle> */
    private function titles(): Collection
    {
        return $this->officialTitles ??= AnnotationTitle::query()
            ->where('active', true)
            ->get();
    }
}
