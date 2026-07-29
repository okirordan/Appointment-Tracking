<?php

namespace Database\Seeders;

use App\Models\MailRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class MoesIncomingMailSeeder extends Seeder
{
    /**
     * @throws JsonException
     */
    public function run(): void
    {
        $path = database_path('seeders/data/moes_incoming_mail_seed.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read MOES incoming-mail seed data at {$path}.");
        }

        $records = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($records) || count($records) !== 532) {
            throw new RuntimeException('The MOES incoming-mail seed must contain exactly 532 records.');
        }

        $capturedBy = User::query()->where('username', 'machieng')->firstOrFail();

        DB::transaction(function () use ($capturedBy, $records): void {
            $now = now();
            $updateColumns = [
                'direction',
                'sender_name',
                'sender_organisation',
                'recipient_name',
                'subject',
                'details',
                'correspondence_reference',
                'letter_date',
                'received_date',
                'sent_date',
                'receipt_method',
                'confidentiality',
                'registry_file_number',
                'captured_by_user_id',
                'deleted_at',
                'updated_at',
            ];

            foreach (array_chunk($records, 100) as $chunk) {
                $rows = array_map(fn (array $record): array => [
                    'register_number' => $record['register_number'],
                    'direction' => 'incoming',
                    'sender_name' => $record['sender_name'],
                    'sender_organisation' => null,
                    'recipient_name' => $record['recipient_name'],
                    'subject' => $record['subject'],
                    'details' => $record['details'],
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
                ], $chunk);

                MailRecord::query()->upsert($rows, ['register_number'], $updateColumns);
            }
        });
    }
}
