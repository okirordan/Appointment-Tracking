<?php

namespace App\Services\Mail;

use App\Models\MailRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MailDuplicateService
{
    public function __construct(private MailAccessScope $access) {}

    /**
     * Return only records the current user is already authorised to view.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function search(User $user, array $input, int $limit = 5): Collection
    {
        $subject = trim((string) ($input['subject'] ?? ''));
        if (mb_strlen($subject) < 3) {
            return collect();
        }

        $tokens = $this->tokens($subject);
        $query = MailRecord::query()
            ->with('capturedBy:id,full_name')
            ->where(function ($matches) use ($subject, $tokens): void {
                $matches->where('subject', 'like', '%'.$this->escapeLike($subject).'%');
                if ($tokens !== []) {
                    $matches->orWhere(function ($allSubjectTokens) use ($tokens): void {
                        foreach ($tokens as $token) {
                            $allSubjectTokens->where('subject', 'like', '%'.$this->escapeLike($token).'%');
                        }
                    });
                }
            })
            ->latest('id')
            ->limit(100);
        $this->access->apply($query, $user);

        return $query->get()
            ->map(fn (MailRecord $mail) => $this->present($mail, $input))
            ->filter(fn (array $item) => $item['match_strength'] >= 2)
            ->sortByDesc(fn (array $item) => [$item['match_strength'], $item['similarity'], $item['id']])
            ->take(max(1, min($limit, 5)))
            ->values();
    }

    public function strongest(User $user, array $input): ?array
    {
        return $this->search($user, $input, 1)->first();
    }

    /** @return array<string, mixed> */
    private function present(MailRecord $mail, array $input): array
    {
        $subject = $this->normalize((string) ($input['subject'] ?? ''));
        $existingSubject = $this->normalize($mail->subject);
        similar_text($subject, $existingSubject, $similarity);

        $sameSubject = $subject !== '' && $subject === $existingSubject;
        $longerSubjectLength = max(mb_strlen($subject), mb_strlen($existingSubject));
        $lengthRatio = $longerSubjectLength === 0
            ? 0
            : min(mb_strlen($subject), mb_strlen($existingSubject)) / $longerSubjectLength;
        $highlyRelevantSubject = $sameSubject || ($similarity >= 92 && $lengthRatio >= 0.9);
        $sameReference = $this->sameOptional($input['correspondence_reference'] ?? null, $mail->correspondence_reference);
        $sameSender = $this->sameOptional($input['sender_name'] ?? null, $mail->sender_name);
        $sameRecipient = $this->sameOptional($input['recipient_name'] ?? null, $mail->recipient_name);
        $inputDate = $input['mail_date'] ?? null;
        $mailDate = $mail->isIncoming() ? $mail->received_date : $mail->sent_date;
        $sameDate = filled($inputDate) && $mailDate?->toDateString() === $inputDate;
        $matchingFields = collect([
            'subject' => $sameSubject,
            'reference number' => $sameReference,
            'sender' => $sameSender,
            'recipient' => $sameRecipient,
            'mail date' => $sameDate,
        ])->filter()->keys()->values()->all();
        $strength = $sameSubject && count($matchingFields) >= 3 ? 3 : ($highlyRelevantSubject ? 2 : 1);

        return [
            'id' => $mail->id,
            'subject' => $mail->subject,
            'register_number' => $mail->register_number,
            'reference_number' => $mail->correspondence_reference,
            'direction' => $mail->direction,
            'sender' => $mail->sender_name,
            'recipient' => $mail->recipient_name,
            'mail_date' => $mailDate?->format('d M Y'),
            'recorded_at' => $mail->created_at?->format('d M Y, H:i'),
            'status' => $mail->status->label(),
            'recorded_by' => $mail->capturedBy?->full_name,
            'similarity' => (int) round($similarity),
            'match_strength' => $strength,
            'matching_fields' => $matchingFields,
            'url' => route('mail.show', $mail),
        ];
    }

    private function sameOptional(mixed $left, mixed $right): bool
    {
        return filled($left) && filled($right) && $this->normalize((string) $left) === $this->normalize((string) $right);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii(trim($value)))) ?? '';
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', $value) ?: [])
            ->map(fn (string $token) => Str::lower($token))
            ->filter(fn (string $token) => mb_strlen($token) >= 3)
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
