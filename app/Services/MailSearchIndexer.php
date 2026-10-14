<?php

namespace App\Services;

use App\Models\MailRecord;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class MailSearchIndexer
{
    private const RELATIONS = [
        'department', 'organizationalUnit', 'sourceDepartment', 'recipientDepartment',
        'sourceStaffUser', 'recipientStaffUser', 'annotationTitle', 'recipientAnnotationTitle',
        'task.assignedTo', 'task.department', 'task.histories',
        'routingTask.assignedTo', 'routingTask.department', 'routingTask.histories',
    ];

    public function rebuild(MailRecord $mail): void
    {
        $mail->loadMissing(self::RELATIONS);

        $parts = [
            $mail->register_number, $mail->external_id, $mail->subject, $mail->details,
            $mail->sender_name, $mail->sender_organisation, $mail->recipient_name,
            $mail->correspondence_reference, $mail->registry_file_number, $mail->dispatch_reference,
        ];

        foreach ([$mail->department, $mail->organizationalUnit, $mail->sourceDepartment, $mail->recipientDepartment] as $office) {
            $parts[] = $office?->name;
            $parts[] = $office?->code;
        }

        foreach ([$mail->sourceStaffUser, $mail->recipientStaffUser] as $staff) {
            $parts[] = $staff?->full_name;
            $parts[] = $staff?->title;
        }

        foreach ([$mail->annotationTitle, $mail->recipientAnnotationTitle] as $title) {
            $parts[] = $title?->shorthand;
            $parts[] = $title?->full_title;
        }

        foreach ([$mail->task, $mail->routingTask] as $task) {
            $parts[] = $task?->assigned_to_name_snapshot;
            $parts[] = $task?->assignedTo?->full_name;
            $parts[] = $task?->assignedTo?->title;
            $parts[] = $task?->department?->name;
            // Current search matches any historical note, not only the latest.
            foreach ($task?->histories ?? [] as $history) {
                $parts[] = $history->note;
            }
        }

        $parts = array_values(array_filter(
            array_map(fn ($part) => $part === null ? null : trim((string) $part), $parts),
            fn ($part) => $part !== null && $part !== '',
        ));
        $searchText = implode(' ', $parts);

        if ($mail->search_text !== $searchText) {
            $mail->forceFill(['search_text' => $searchText])->saveQuietly();
        }
    }

    /** @param iterable<int, MailRecord|int> $records */
    public function rebuildMany(iterable $records): void
    {
        $ids = [];
        $models = [];
        foreach ($records as $record) {
            if ($record instanceof MailRecord) {
                $models[] = $record;
            } else {
                $ids[] = (int) $record;
            }
        }

        if ($ids !== []) {
            $models = array_merge($models, MailRecord::query()->whereKey($ids)->get()->all());
        }

        $collection = new EloquentCollection($models);
        $collection->loadMissing(self::RELATIONS);
        foreach ($collection as $mail) {
            $this->rebuild($mail);
        }
    }
}
