<?php

namespace App\Services\Imports;

use App\Models\Department;
use App\Models\Division;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MailRecord;
use App\Models\Task;
use App\Models\User;
use App\Models\Workstream;
use App\Services\AuditLogger;
use App\Services\Mail\MailRecordService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Throwable;

class StagedImportService
{
    private const MAX_ROWS = 10000;

    public function __construct(
        private ImportSchemaRegistry $registry,
        private AuditLogger $audit,
        private MailRecordService $mailRecords,
    ) {}

    public function stage(User $actor, UploadedFile $file, string $source, string $entity): ImportBatch
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($checksum === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }
        if (ImportBatch::where('source_system', $source)->where('checksum', $checksum)->exists()) {
            throw new RuntimeException('This exact file has already been staged for this source system.');
        }

        $key = $file->storeAs(
            'imports/'.now()->format('Y/m'),
            Str::uuid().'.'.strtolower($file->getClientOriginalExtension()),
            'local',
        );
        if ($key === false) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        $batch = null;

        try {
            $batch = ImportBatch::create([
                'initiated_by_user_id' => $actor->id,
                'source_system' => trim($source),
                'entity_type' => $entity,
                'status' => 'validating',
                'original_filename' => basename($file->getClientOriginalName()),
                'storage_key' => $key,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => (int) $file->getSize(),
                'checksum' => $checksum,
            ]);
            $this->readRows($batch);

            return $batch->refresh();
        } catch (Throwable $exception) {
            $batch?->forceDelete();
            Storage::disk('local')->delete($key);

            throw $exception;
        }
    }

    private function readRows(ImportBatch $batch): void
    {
        $path = Storage::disk('local')->path($batch->storage_key);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $reader = match ($extension) {
            'xlsx' => new XlsxReader,
            'csv' => new CsvReader,
            default => throw new RuntimeException('Only CSV and XLSX files are supported.'),
        };

        $opened = false;
        $headers = null;
        $physicalRow = 0;
        $count = 0;
        $valid = 0;

        try {
            $reader->open($path);
            $opened = true;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $physicalRow++;
                    $values = $row->toArray();

                    if ($this->blankRow($values)) {
                        continue;
                    }

                    if ($headers === null) {
                        $headers = $this->registry->normalizeHeaders($batch->entity_type, $values);
                        $this->validateHeaders($batch->entity_type, $headers);

                        continue;
                    }

                    $count++;
                    if ($count > self::MAX_ROWS) {
                        throw new RuntimeException('The file exceeds the 10,000-row import limit. Split it into smaller batches.');
                    }

                    $data = array_fill_keys($this->registry->schemas()[$batch->entity_type], null);
                    foreach ($headers as $index => $header) {
                        if ($header === '') {
                            continue;
                        }
                        $data[$header] = $values[$index] ?? null;
                    }

                    $normalized = $this->normalizeRow($batch->entity_type, $data);
                    $issues = $this->validateRow($batch->entity_type, $normalized);
                    ImportRow::create([
                        'import_batch_id' => $batch->id,
                        'row_number' => $physicalRow,
                        'status' => $issues === [] ? 'valid' : 'invalid',
                        'normalized_json' => $normalized,
                        'issues_json' => $issues ?: null,
                    ]);

                    if ($issues === []) {
                        $valid++;
                    }
                    if ($count % 500 === 0) {
                        $batch->update(['total_rows' => $count, 'valid_rows' => $valid]);
                    }
                }

                break;
            }
        } finally {
            if ($opened) {
                $reader->close();
            }
        }

        if ($headers === null) {
            throw new RuntimeException('The spreadsheet does not contain a header row.');
        }
        if ($count === 0) {
            throw new RuntimeException('The spreadsheet does not contain any data rows.');
        }

        $batch->update([
            'status' => $valid === $count ? 'ready' : 'needs_attention',
            'total_rows' => $count,
            'valid_rows' => $valid,
            'failed_rows' => $count - $valid,
            'mapping_json' => ['headers' => array_values(array_filter($headers))],
        ]);
    }

    /** @param list<mixed> $values */
    private function blankRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value instanceof DateTimeInterface || trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $headers */
    private function validateHeaders(string $entity, array $headers): void
    {
        $present = array_values(array_filter($headers));
        $duplicates = array_keys(array_filter(array_count_values($present), fn (int $count): bool => $count > 1));
        if ($duplicates !== []) {
            throw new RuntimeException('Duplicate spreadsheet column(s): '.implode(', ', $duplicates).'.');
        }

        $missing = array_values(array_diff($this->registry->required($entity), $present));
        if ($missing !== []) {
            throw new RuntimeException('Missing required spreadsheet column(s): '.implode(', ', $missing).'.');
        }
    }

    private function normalizeRow(string $entity, array $data): array
    {
        $normalized = [];
        foreach ($data as $field => $value) {
            if ($value instanceof DateTimeInterface) {
                $normalized[$field] = $value->format('Y-m-d');
            } elseif (is_string($value)) {
                $normalized[$field] = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
            } else {
                $normalized[$field] = $value;
            }
        }

        if (in_array($entity, ['incoming_mail', 'outgoing_mail'], true)) {
            $dateField = $entity === 'incoming_mail' ? 'date_received' : 'date_sent';
            $normalized[$dateField] = $this->normalizeDate($normalized[$dateField] ?? null);
            if (array_key_exists('letter_date', $normalized) && $normalized['letter_date'] !== null && $normalized['letter_date'] !== '') {
                $normalized['letter_date'] = $this->normalizeDate($normalized['letter_date']);
            }
            $normalized['confidentiality'] = strtolower(trim((string) ($normalized['confidentiality'] ?? 'normal'))) ?: 'normal';
            if ($entity === 'incoming_mail') {
                $method = strtolower(trim((string) ($normalized['receipt_method'] ?? '')));
                $normalized['receipt_method'] = $method === '' ? null : $method;
            }
        }

        return $normalized;
    }

    private function normalizeDate(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        if (is_numeric($text)) {
            $serial = (float) $text;
            if ($serial >= 1 && $serial <= 100000) {
                return (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))
                    ->modify('+'.(int) floor($serial).' days')
                    ->format('Y-m-d');
            }
        }

        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd M Y', 'j M Y', 'd F Y', 'j F Y', 'd-M-Y', 'j-M-Y', 'd-M-y', 'j-M-y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $text, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return $text;
    }

    private function validateRow(string $entity, array $data): array
    {
        if (in_array($entity, ['incoming_mail', 'outgoing_mail'], true)) {
            return $this->validateMailRow($entity, $data);
        }

        $issues = [];
        foreach ($this->registry->required($entity) as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $issues[] = ['code' => 'required', 'field' => $field, 'message' => "{$field} is required."];
            }
        }
        if (isset($data['progress_percent']) && $data['progress_percent'] !== '' && (! is_numeric($data['progress_percent']) || $data['progress_percent'] < 0 || $data['progress_percent'] > 100)) {
            $issues[] = ['code' => 'invalid_progress', 'field' => 'progress_percent', 'message' => 'Progress must be from 0 to 100.'];
        }
        if (isset($data['priority']) && ! in_array(strtolower((string) $data['priority']), ['low', 'normal', 'high', 'urgent'], true)) {
            $issues[] = ['code' => 'invalid_priority', 'field' => 'priority', 'message' => 'Priority is not recognised.'];
        }

        return $issues;
    }

    private function validateMailRow(string $entity, array $data): array
    {
        $dateField = $entity === 'incoming_mail' ? 'date_received' : 'date_sent';
        $rules = [
            'external_id' => ['nullable', 'string', 'max:120'],
            'from' => ['required', 'string', 'max:255'],
            'to' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:500'],
            $dateField => ['required', 'date_format:Y-m-d'],
            'ref_no' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:10000'],
            'letter_date' => ['nullable', 'date_format:Y-m-d'],
            'sender_organisation' => ['nullable', 'string', 'max:255'],
            'confidentiality' => ['required', 'in:normal,confidential,restricted'],
            'registry_file_number' => ['nullable', 'string', 'max:255'],
        ];
        if ($entity === 'incoming_mail') {
            $rules['receipt_method'] = ['nullable', 'in:hand,courier,email,post,other'];
        }

        $validator = Validator::make($data, $rules, [], [
            'from' => 'FROM',
            'to' => 'TO',
            'date_received' => 'DATE RECEIVED',
            'date_sent' => 'DATE SENT',
            'ref_no' => 'REF NO',
        ]);

        $issues = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            foreach ($messages as $message) {
                $issues[] = [
                    'code' => str_contains(strtolower($message), 'required') ? 'required' : 'invalid',
                    'field' => $field,
                    'message' => $message,
                ];
            }
        }

        return $issues;
    }

    public function confirm(ImportBatch $batch, User $actor): void
    {
        DB::transaction(function () use ($batch, $actor): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($locked->status !== 'ready') {
                throw new RuntimeException('Only a fully validated batch can be confirmed.');
            }

            $locked->update(['status' => 'importing', 'confirmed_at' => now()]);
            $created = 0;
            $updated = 0;

            $locked->rows()->where('status', 'valid')->orderBy('id')->chunkById(500, function ($rows) use ($locked, $actor, &$created, &$updated): void {
                foreach ($rows as $row) {
                    $result = $this->upsert(
                        $locked->entity_type,
                        $locked->source_system,
                        $row->normalized_json,
                        $row->row_number,
                        $actor,
                    );
                    if ($result === 'updated') {
                        $updated++;
                    } else {
                        $created++;
                    }
                    $row->update(['status' => 'imported']);
                }
            });

            $locked->update([
                'status' => 'completed',
                'created_rows' => $created,
                'updated_rows' => $updated,
                'completed_at' => now(),
            ]);
            $this->audit->log('import', 'Confirmed recurring data import', $actor, 'ImportBatch', $locked->id, [
                'source_system' => $locked->source_system,
                'entity_type' => $locked->entity_type,
                'rows' => $locked->total_rows,
                'created' => $created,
                'updated' => $updated,
            ]);
        });
    }

    private function upsert(string $entity, string $source, array $data, int $rowNumber, User $actor): string
    {
        return match ($entity) {
            'departments' => $this->save(Department::class, ['external_id' => $source.':'.$data['external_id']], ['code' => $data['code'], 'name' => $data['name'], 'active' => $this->bool($data['active'] ?? true)]),
            'divisions' => $this->save(Division::class, ['external_id' => $source.':'.$data['external_id']], ['department_id' => $this->departmentId($source, $data), 'code' => $data['code'], 'name' => $data['name'], 'active' => $this->bool($data['active'] ?? true)]),
            'workstreams' => $this->save(Workstream::class, ['external_id' => $source.':'.$data['external_id']], ['type' => strtolower($data['type']), 'code' => $data['code'] ?: null, 'name' => $data['name'], 'description' => $data['description'] ?: null, 'department_id' => $this->nullableDepartmentId($source, $data), 'active' => $this->bool($data['active'] ?? true)]),
            'tasks' => $this->saveTask($source, $data, $actor),
            'incoming_mail' => $this->saveMail('incoming', $source, $data, $rowNumber, $actor),
            'outgoing_mail' => $this->saveMail('outgoing', $source, $data, $rowNumber, $actor),
            default => throw new RuntimeException('Unsupported import entity.'),
        };
    }

    private function save(string $model, array $match, array $values): string
    {
        $record = $model::firstOrNew($match);
        $exists = $record->exists;
        $record->fill($values)->save();

        return $exists ? 'updated' : 'created';
    }

    private function saveMail(string $direction, string $source, array $data, int $rowNumber, User $actor): string
    {
        $entity = $direction === 'incoming' ? 'incoming_mail' : 'outgoing_mail';
        $providedId = trim((string) ($data['external_id'] ?? ''));
        $externalId = $providedId !== ''
            ? $source.':'.$entity.':'.$providedId
            : $source.':'.$entity.':row:'.str_pad((string) $rowNumber, 6, '0', STR_PAD_LEFT);

        $mail = MailRecord::withTrashed()->where('external_id', $externalId)->first();
        $exists = $mail !== null;
        $mail ??= new MailRecord([
            'register_number' => $this->mailRecords->nextRegisterNumber($direction),
            'external_id' => $externalId,
        ]);
        if ($mail->trashed()) {
            $mail->restore();
        }

        $mail->fill([
            'direction' => $direction,
            'sender_name' => trim((string) $data['from']),
            'sender_organisation' => $this->nullableText($data['sender_organisation'] ?? null),
            'recipient_name' => trim((string) $data['to']),
            'subject' => trim((string) $data['subject']),
            'details' => $this->nullableText($data['details'] ?? null),
            'correspondence_reference' => $this->nullableText($data['ref_no'] ?? null),
            'letter_date' => $data['letter_date'] ?? null,
            'received_date' => $direction === 'incoming' ? $data['date_received'] : null,
            'sent_date' => $direction === 'outgoing' ? $data['date_sent'] : null,
            'receipt_method' => $direction === 'incoming' ? ($data['receipt_method'] ?? null) : null,
            'confidentiality' => $data['confidentiality'] ?? 'normal',
            'registry_file_number' => $this->nullableText($data['registry_file_number'] ?? null),
            'captured_by_user_id' => $actor->id,
        ]);
        $mail->save();

        return $exists ? 'updated' : 'created';
    }

    private function departmentId(string $source, array $data): int
    {
        $id = $this->nullableDepartmentId($source, $data);
        if (! $id) {
            throw new RuntimeException('The referenced department does not exist.');
        }

        return $id;
    }

    private function nullableDepartmentId(string $source, array $data): ?int
    {
        return Department::where('external_id', $source.':'.($data['department_external_id'] ?? ''))
            ->orWhere('code', $data['department_code'] ?? '')
            ->value('id');
    }

    private function saveTask(string $source, array $data, User $actor): string
    {
        $department = $this->nullableDepartmentId($source, $data);
        $assignee = User::where('external_id', $source.':'.($data['assignee_external_id'] ?? ''))->first();

        return $this->save(Task::class, ['external_id' => $source.':'.$data['external_id']], [
            'reference' => $data['reference'],
            'title' => $data['title'],
            'description' => $data['description'] ?: null,
            'assignment_level' => $department ? 'department' : 'ps',
            'assigned_by_user_id' => $actor->id,
            'assigned_to_user_id' => $assignee?->id,
            'assigned_to_name_snapshot' => $assignee?->full_name ?? 'Unassigned imported record',
            'department_id' => $department,
            'priority' => strtolower($data['priority']),
            'workflow_status' => strtolower(str_replace(' ', '_', $data['status'])),
            'progress_percent' => (int) ($data['progress_percent'] ?: 0),
            'due_date' => $data['due_date'] ?: null,
            'completed_at' => $data['completed_at'] ?: null,
            'created_at' => $data['assigned_at'],
        ]);
    }

    private function bool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'active'], true);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
