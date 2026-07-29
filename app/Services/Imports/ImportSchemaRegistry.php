<?php

namespace App\Services\Imports;

use Illuminate\Support\Str;

class ImportSchemaRegistry
{
    public function schemas(): array
    {
        return [
            'departments' => ['external_id', 'code', 'name', 'active'],
            'divisions' => ['external_id', 'department_external_id', 'department_code', 'code', 'name', 'active'],
            'workstreams' => ['external_id', 'type', 'code', 'name', 'description', 'department_external_id', 'active'],
            'tasks' => ['external_id', 'reference', 'title', 'description', 'workstream_external_id', 'department_external_id', 'division_external_id', 'assignee_external_id', 'priority', 'status', 'progress_percent', 'assigned_at', 'due_date', 'completed_at'],
            'incoming_mail' => ['external_id', 'from', 'to', 'subject', 'date_received', 'ref_no', 'details', 'letter_date', 'sender_organisation', 'receipt_method', 'confidentiality', 'registry_file_number'],
            'outgoing_mail' => ['external_id', 'from', 'to', 'subject', 'date_sent', 'ref_no', 'details', 'letter_date', 'sender_organisation', 'confidentiality', 'registry_file_number'],
        ];
    }

    public function labels(): array
    {
        return [
            'departments' => 'Departments',
            'divisions' => 'Divisions',
            'workstreams' => 'Workstreams',
            'tasks' => 'Assignments / tasks',
            'incoming_mail' => 'Incoming mail',
            'outgoing_mail' => 'Outgoing mail',
        ];
    }

    public function required(string $entity): array
    {
        return match ($entity) {
            'departments' => ['external_id', 'code', 'name'],
            'divisions' => ['external_id', 'code', 'name'],
            'workstreams' => ['external_id', 'type', 'name'],
            'tasks' => ['external_id', 'reference', 'title', 'priority', 'status', 'assigned_at'],
            'incoming_mail' => ['from', 'to', 'subject', 'date_received'],
            'outgoing_mail' => ['from', 'to', 'subject', 'date_sent'],
            default => [],
        };
    }

    /**
     * @param  list<mixed>  $headers
     * @return list<string>
     */
    public function normalizeHeaders(string $entity, array $headers): array
    {
        $aliases = $this->aliases($entity);

        return array_map(function (mixed $header) use ($aliases): string {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $header)) ?? '';
            $normalized = Str::snake(mb_strtolower($value));

            return $aliases[$normalized] ?? $normalized;
        }, $headers);
    }

    /** @return array<string, string> */
    private function aliases(string $entity): array
    {
        if (! in_array($entity, ['incoming_mail', 'outgoing_mail'], true)) {
            return [];
        }

        $aliases = [
            'sender' => 'from',
            'sender_name' => 'from',
            'recipient' => 'to',
            'recipient_name' => 'to',
            'reference' => 'ref_no',
            'reference_no' => 'ref_no',
            'reference_number' => 'ref_no',
            'correspondence_reference' => 'ref_no',
            'organisation' => 'sender_organisation',
            'organization' => 'sender_organisation',
            'sender_organization' => 'sender_organisation',
            'registry_no' => 'registry_file_number',
            'registry_number' => 'registry_file_number',
        ];

        if ($entity === 'incoming_mail') {
            $aliases['received_date'] = 'date_received';
            $aliases['date_of_receipt'] = 'date_received';
        } else {
            $aliases['sent_to'] = 'to';
            $aliases['sent'] = 'date_sent';
            $aliases['received'] = 'letter_date';
            $aliases['sent_date'] = 'date_sent';
            $aliases['date_dispatched'] = 'date_sent';
        }

        return $aliases;
    }
}
