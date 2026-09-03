<?php

namespace App\Services;

use App\Models\Correspondence;
use App\Models\CorrespondenceRecipient;
use App\Models\MailRecord;
use App\Models\OrganizationalUnit;
use App\Models\Task;
use App\Models\User;

class SecretaryCorrespondenceMigrationService
{
    /**
     * Move records originally registered by a secretary into their new
     * office. Routing events and recipients are deliberately immutable: they
     * describe where the correspondence actually travelled at the time.
     *
     * This method must be called inside the attachment transaction.
     *
     * @return array{mail_records: int, correspondences: int, tasks: int}
     */
    public function moveOwnedHistory(User $secretary, OrganizationalUnit $unit): array
    {
        $mailIds = MailRecord::withTrashed()
            ->where('captured_by_user_id', $secretary->id)
            ->whereNull('source_mail_record_id')
            ->lockForUpdate()
            ->pluck('id');

        if ($mailIds->isEmpty()) {
            return ['mail_records' => 0, 'correspondences' => 0, 'tasks' => 0];
        }

        $correspondenceIds = Correspondence::query()
            ->whereIn('originating_mail_record_id', $mailIds)
            ->lockForUpdate()
            ->pluck('id');

        $mailCount = MailRecord::withTrashed()
            ->whereKey($mailIds)
            ->update([
                'organizational_unit_id' => $unit->id,
                'department_id' => $unit->department_id,
                'updated_at' => now(),
            ]);

        $correspondenceCount = Correspondence::query()
            ->whereKey($correspondenceIds)
            ->update([
                'organizational_unit_id' => $unit->id,
                'department_id' => $unit->department_id,
                'updated_at' => now(),
            ]);

        $taskIds = MailRecord::withTrashed()
            ->whereKey($mailIds)
            ->get(['task_id', 'routing_task_id'])
            ->flatMap(fn (MailRecord $mail) => [$mail->task_id, $mail->routing_task_id])
            ->merge(CorrespondenceRecipient::query()
                ->whereIn('correspondence_id', $correspondenceIds)
                ->whereNotNull('task_id')
                ->pluck('task_id'))
            ->filter()
            ->unique()
            ->values();

        $taskCount = Task::withTrashed()
            ->whereKey($taskIds)
            ->where(fn ($task) => $task
                ->where('creator_user_id', $secretary->id)
                ->orWhere('assigned_by_user_id', $secretary->id))
            ->update([
                'owner_organizational_unit_id' => $unit->id,
                'updated_at' => now(),
            ]);

        return [
            'mail_records' => $mailCount,
            'correspondences' => $correspondenceCount,
            'tasks' => $taskCount,
        ];
    }
}
