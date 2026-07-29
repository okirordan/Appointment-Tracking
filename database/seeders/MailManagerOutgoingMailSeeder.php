<?php

namespace Database\Seeders;

use App\Models\MailRecord;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class MailManagerOutgoingMailSeeder extends Seeder
{
    private const EXPECTED_RECORDS = 48558;

    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $records = $this->loadRecords();
        $capturedBy = User::query()->where('username', 'machieng')->firstOrFail();

        [$inserted, $skipped, $enriched] = DB::transaction(function () use ($capturedBy, $records): array {
            $fingerprintCounts = [];

            MailRecord::query()
                ->where('direction', 'outgoing')
                ->get(['sender_name', 'subject', 'sent_date'])
                ->each(function (MailRecord $mail) use (&$fingerprintCounts): void {
                    $fingerprint = $this->fingerprint(
                        $mail->sender_name,
                        $mail->subject,
                        $mail->sent_date,
                    );
                    $fingerprintCounts[$fingerprint] = ($fingerprintCounts[$fingerprint] ?? 0) + 1;
                });

            $missingByExactFingerprint = [];
            $missingByLooseFingerprint = [];
            MailRecord::query()
                ->where('direction', 'outgoing')
                ->whereNull('received_date')
                ->get(['id', 'sender_name', 'recipient_name', 'subject', 'sent_date'])
                ->each(function (MailRecord $mail) use (&$missingByExactFingerprint, &$missingByLooseFingerprint): void {
                    $exactFingerprint = $this->enrichmentFingerprint(
                        $mail->sender_name,
                        $mail->recipient_name,
                        $mail->subject,
                        $mail->sent_date,
                    );
                    $looseFingerprint = $this->fingerprint(
                        $mail->sender_name,
                        $mail->subject,
                        $mail->sent_date,
                    );
                    $missingByExactFingerprint[$exactFingerprint] ??= new \SplQueue;
                    $missingByExactFingerprint[$exactFingerprint]->enqueue($mail->id);
                    $missingByLooseFingerprint[$looseFingerprint] ??= new \SplQueue;
                    $missingByLooseFingerprint[$looseFingerprint]->enqueue($mail->id);
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
            $enriched = 0;
            $enrichedIds = [];

            $takeMissingId = static function (?\SplQueue $queue) use (&$enrichedIds): ?int {
                while ($queue !== null && ! $queue->isEmpty()) {
                    $candidateId = $queue->dequeue();
                    if (! isset($enrichedIds[$candidateId])) {
                        return $candidateId;
                    }
                }

                return null;
            };

            foreach ($records as $record) {
                $externalId = $this->externalId($record['sequence']);
                $fingerprint = $this->fingerprint(
                    $record['sender_name'],
                    $record['subject'],
                    $record['sent_date'],
                );

                if (isset($existingExternalIds[$externalId])) {
                    if (($fingerprintCounts[$fingerprint] ?? 0) > 0) {
                        $fingerprintCounts[$fingerprint]--;
                    }
                    $skipped++;

                    continue;
                }

                if ($record['received_date'] !== null) {
                    $exactFingerprint = $this->enrichmentFingerprint(
                        $record['sender_name'],
                        $record['recipient_name'],
                        $record['subject'],
                        $record['sent_date'],
                    );
                    $missingId = $takeMissingId($missingByExactFingerprint[$exactFingerprint] ?? null)
                        ?? $takeMissingId($missingByLooseFingerprint[$fingerprint] ?? null);

                    if ($missingId !== null) {
                        MailRecord::query()
                            ->whereKey($missingId)
                            ->whereNull('received_date')
                            ->update([
                                'received_date' => $record['received_date'],
                                'updated_at' => $now,
                            ]);
                        $enrichedIds[$missingId] = true;
                        $enriched++;
                    }
                }

                if (($fingerprintCounts[$fingerprint] ?? 0) > 0) {
                    $fingerprintCounts[$fingerprint]--;
                    $skipped++;

                    continue;
                }

                $pending[] = [
                    'direction' => 'outgoing',
                    'register_number' => sprintf('OM-MMP-%05d', $record['sequence']),
                    'external_id' => $externalId,
                    'sender_name' => $record['sender_name'],
                    'sender_organisation' => null,
                    'recipient_name' => $record['recipient_name'],
                    'subject' => $record['subject'],
                    'details' => $this->auditDetails($record),
                    'correspondence_reference' => null,
                    'letter_date' => null,
                    'received_date' => $record['received_date'],
                    'sent_date' => $record['sent_date'],
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

            return [$inserted, $skipped, $enriched];
        });

        $this->command?->info(
            "Mail Manager outgoing mail: {$inserted} inserted, {$skipped} already present, {$enriched} enriched."
        );
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function loadRecords(): array
    {
        $path = database_path('seeders/data/mail_manager_outgoing_mail_seed.json.gz');
        $compressed = file_get_contents($path);
        $contents = $compressed === false ? false : gzdecode($compressed);

        if ($contents === false) {
            throw new RuntimeException("Unable to read Mail Manager outgoing-mail seed data at {$path}.");
        }

        $records = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (
            ! is_array($records)
            || count($records) !== self::EXPECTED_RECORDS
            || ($records[0]['sequence'] ?? null) !== 1
            || ($records[array_key_last($records)]['sequence'] ?? null) !== self::EXPECTED_RECORDS
        ) {
            throw new RuntimeException(
                'The Mail Manager outgoing-mail seed must contain 48,558 ordered records.'
            );
        }

        return $records;
    }

    private function externalId(int $sequence): string
    {
        return sprintf('mail-manager-outgoing-mhtml-2026-07-23:%05d', $sequence);
    }

    private function fingerprint(
        ?string $sender,
        ?string $subject,
        CarbonInterface|string|null $sentDate,
    ): string {
        if ($sentDate instanceof CarbonInterface) {
            $sentDate = $sentDate->toDateString();
        }

        return hash('sha256', implode("\x1F", [
            $this->normalize($sender),
            $this->normalize($subject),
            (string) $sentDate,
        ]));
    }

    private function enrichmentFingerprint(
        ?string $sender,
        ?string $recipient,
        ?string $subject,
        CarbonInterface|string|null $sentDate,
    ): string {
        if ($sentDate instanceof CarbonInterface) {
            $sentDate = $sentDate->toDateString();
        }

        return hash('sha256', implode("\x1F", [
            $this->normalize($sender),
            $this->normalize($recipient),
            $this->normalize($subject),
            (string) $sentDate,
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
        $corrections = [];

        if ($record['received_date_corrected']) {
            $corrections[] = "Printed received date '{$record['received_date_raw']}' was normalized to {$record['received_date']}.";
        }

        if ($record['sent_date_corrected']) {
            $corrections[] = "Printed sent date '{$record['sent_date_raw']}' was normalized to {$record['sent_date']}.";
        }

        if ($corrections === []) {
            return null;
        }

        return "Source: Mail Manager MHTML row {$record['sequence']}. ".implode(' ', $corrections);
    }
}
