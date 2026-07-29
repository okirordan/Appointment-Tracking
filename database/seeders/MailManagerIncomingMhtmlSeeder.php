<?php

namespace Database\Seeders;

use App\Models\MailRecord;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class MailManagerIncomingMhtmlSeeder extends Seeder
{
    private const EXPECTED_RECORDS = 24892;

    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $records = $this->loadRecords();
        $capturedBy = User::query()->where('username', 'machieng')->firstOrFail();

        [$inserted, $skipped] = DB::transaction(function () use ($capturedBy, $records): array {
            $fingerprintCounts = [];

            MailRecord::query()
                ->where('direction', 'incoming')
                ->get([
                    'sender_name',
                    'recipient_name',
                    'subject',
                    'received_date',
                    'correspondence_reference',
                ])
                ->each(function (MailRecord $mail) use (&$fingerprintCounts): void {
                    $fingerprint = $this->fingerprint(
                        $mail->sender_name,
                        $mail->recipient_name,
                        $mail->subject,
                        $mail->received_date,
                        $mail->correspondence_reference,
                    );
                    $fingerprintCounts[$fingerprint] = ($fingerprintCounts[$fingerprint] ?? 0) + 1;
                });

            $existingExternalIds = MailRecord::withTrashed()
                ->whereNotNull('external_id')
                ->pluck('external_id')
                ->flip()
                ->all();

            $now = now();
            $pending = [];
            $inserted = 0;
            $skipped = 0;

            foreach ($records as $record) {
                $externalId = $this->externalId($record['sequence']);
                $fingerprint = $this->fingerprint(
                    $record['sender_name'],
                    $record['recipient_name'],
                    $record['subject'],
                    $record['received_date'],
                    $record['correspondence_reference'],
                );

                if (isset($existingExternalIds[$externalId])) {
                    if (($fingerprintCounts[$fingerprint] ?? 0) > 0) {
                        $fingerprintCounts[$fingerprint]--;
                    }
                    $skipped++;

                    continue;
                }

                if (($fingerprintCounts[$fingerprint] ?? 0) > 0) {
                    $fingerprintCounts[$fingerprint]--;
                    $skipped++;

                    continue;
                }

                $pending[] = [
                    'direction' => 'incoming',
                    'register_number' => sprintf('IM-MMH-%05d', $record['sequence']),
                    'external_id' => $externalId,
                    'sender_name' => $record['sender_name'],
                    'sender_organisation' => null,
                    'recipient_name' => $record['recipient_name'],
                    'subject' => $record['subject'],
                    'details' => $this->auditDetails($record),
                    'correspondence_reference' => $record['correspondence_reference'],
                    'letter_date' => null,
                    'received_date' => $record['received_date'],
                    'sent_date' => null,
                    'receipt_method' => null,
                    'confidentiality' => 'normal',
                    'registry_file_number' => null,
                    'captured_by_user_id' => $capturedBy->id,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $inserted++;

                if (count($pending) === 500) {
                    MailRecord::query()->insert($pending);
                    $pending = [];
                }
            }

            if ($pending !== []) {
                MailRecord::query()->insert($pending);
            }

            return [$inserted, $skipped];
        });

        $this->command?->info(
            "Mail Manager incoming MHTML: {$inserted} inserted, {$skipped} already present."
        );
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function loadRecords(): array
    {
        $path = database_path('seeders/data/mail_manager_incoming_mhtml_seed.json.gz');
        $compressed = file_get_contents($path);
        $contents = $compressed === false ? false : gzdecode($compressed);

        if ($contents === false) {
            throw new RuntimeException("Unable to read Mail Manager incoming MHTML seed data at {$path}.");
        }

        $records = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (
            ! is_array($records)
            || count($records) !== self::EXPECTED_RECORDS
            || ($records[0]['sequence'] ?? null) !== 1
            || ($records[array_key_last($records)]['sequence'] ?? null) !== self::EXPECTED_RECORDS
        ) {
            throw new RuntimeException(
                'The Mail Manager incoming MHTML seed must contain 24,892 ordered records.'
            );
        }

        return $records;
    }

    private function externalId(int $sequence): string
    {
        return sprintf('mail-manager-incoming-mhtml-2026-07-23:%05d', $sequence);
    }

    private function fingerprint(
        ?string $sender,
        ?string $recipient,
        ?string $subject,
        CarbonInterface|string|null $receivedDate,
        ?string $reference,
    ): string {
        if ($receivedDate instanceof CarbonInterface) {
            $receivedDate = $receivedDate->toDateString();
        }

        return hash('sha256', implode("\x1F", [
            $this->normalize($sender),
            $this->normalize($recipient),
            $this->normalize($subject),
            (string) $receivedDate,
            $this->normalize($reference),
        ]));
    }

    private function normalize(?string $value): string
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value ?? ''));

        return trim($normalized ?? '');
    }

    /** @param array<string, mixed> $record */
    private function auditDetails(array $record): ?string
    {
        if ($record['source_incomplete']) {
            return "Source: Mail Manager incoming MHTML row {$record['sequence']}. "
                .'The source row was incomplete; its printed received date and reference were missing.';
        }

        if (! $record['received_date_corrected']) {
            return null;
        }

        return "Source: Mail Manager incoming MHTML row {$record['sequence']}. "
            ."Printed received date '{$record['received_date_raw']}' was normalized to {$record['received_date']}.";
    }
}
