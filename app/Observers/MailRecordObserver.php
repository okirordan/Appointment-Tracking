<?php

namespace App\Observers;

use App\Jobs\ReindexMailSearch;
use App\Models\MailRecord;

class MailRecordObserver
{
    private const SEARCH_ATTRIBUTES = [
        'register_number', 'external_id', 'subject', 'details', 'sender_name',
        'sender_organisation', 'recipient_name', 'correspondence_reference',
        'registry_file_number', 'dispatch_reference', 'department_id',
        'organizational_unit_id', 'source_department_id', 'recipient_department_id',
        'source_staff_user_id', 'recipient_staff_user_id', 'annotation_title_id',
        'recipient_annotation_title_id', 'task_id', 'routing_task_id',
    ];

    public function saved(MailRecord $mail): void
    {
        if ($mail->wasRecentlyCreated || $mail->wasChanged(self::SEARCH_ATTRIBUTES)) {
            ReindexMailSearch::dispatch([(int) $mail->id])->afterCommit();
        }
    }
}
