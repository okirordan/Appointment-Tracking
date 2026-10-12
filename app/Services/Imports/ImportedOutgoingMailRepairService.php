<?php

namespace App\Services\Imports;

use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Services\Mail\MinistryShorthand;
use Illuminate\Support\Facades\DB;

class ImportedOutgoingMailRepairService
{
    public function __construct(private MinistryShorthand $shorthand) {}

    /** @return array{repaired: int, notes_created: int, notes_updated: int} */
    public function repair(): array
    {
        $result = ['repaired' => 0, 'notes_created' => 0, 'notes_updated' => 0];

        MailRecord::query()
            ->where('direction', 'outgoing')
            ->whereNotNull('external_id')
            ->where('details', 'like', '{%')
            ->orderBy('id')
            ->chunkById(100, function ($mails) use (&$result): void {
                DB::transaction(function () use ($mails, &$result): void {
                    foreach ($mails as $mail) {
                        $outcome = $this->repairMail($mail);
                        if ($outcome === null) {
                            continue;
                        }
                        $result['repaired']++;
                        if ($outcome === 'created') {
                            $result['notes_created']++;
                        } elseif ($outcome === 'updated') {
                            $result['notes_updated']++;
                        }
                    }
                });
            });

        return $result;
    }

    /**
     * Normalize one imported record immediately after it is saved. The method is
     * harmless for ordinary outgoing mail and already-normalized records.
     */
    public function repairMail(MailRecord $mail): ?string
    {
        if ($mail->direction !== 'outgoing' || $mail->external_id === null || ! is_string($mail->details)) {
            return null;
        }
        $payload = json_decode($mail->details, true);
        if (! is_array($payload)
            || ! array_key_exists('MailDetails', $payload)
            || ! array_key_exists('CorrespondenceDetails', $payload)
            || ! is_string($payload['CorrespondenceDetails'])) {
            return null;
        }

        $recipient = collect(explode(',', $mail->recipient_name))
            ->map(fn (string $name) => $this->shorthand->label($name) ?? trim($name))
            ->implode(', ');
        DB::table('mail_import_payloads')->updateOrInsert(
            ['mail_record_id' => $mail->id],
            [
                'raw_details' => $mail->details,
                'original_sender_name' => $mail->sender_name,
                'original_recipient_name' => $mail->recipient_name,
                'repaired_at' => now(),
            ],
        );

        $mail->forceFill([
            'sender_name' => 'PS/ES',
            'recipient_name' => $recipient,
            'details' => $mail->subject,
        ])->save();

        $body = $payload['CorrespondenceDetails'];
        if (trim($body) === '') {
            return 'none';
        }

        $entry = CorrespondenceUpdate::query()
            ->where('correspondence_id', $mail->correspondence_id)
            ->whereIn('entry_method', ['excel_register_20260924', 'imported_register'])
            ->first();
        $created = $entry === null;
        $entry ??= new CorrespondenceUpdate([
            'correspondence_id' => $mail->correspondence_id,
            'type' => 'note',
            'entry_method' => 'imported_register',
            'performed_by_name_snapshot' => 'Imported mail register',
            'created_at' => $mail->created_at ?? now(),
        ]);
        $entry->forceFill([
            'body' => $body,
            'source_name_snapshot' => 'PS/ES',
            'performed_by_office_snapshot' => 'PS/ES',
            'destination_office_snapshot' => $recipient,
            'occurred_at' => $entry->occurred_at ?? $mail->sent_date ?? $mail->created_at ?? now(),
        ])->save();

        return $created ? 'created' : 'updated';
    }
}
