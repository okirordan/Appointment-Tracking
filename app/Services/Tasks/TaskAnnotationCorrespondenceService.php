<?php

namespace App\Services\Tasks;

use App\Models\AnnotationTitle;
use App\Models\CorrespondenceRecipient;
use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\Mail\OrganizationalRoutingLabel;
use Illuminate\Support\Collection;

class TaskAnnotationCorrespondenceService
{
    public function __construct(private OrganizationalRoutingLabel $routingLabel) {}

    /** Persist one correspondence entry per mail thread linked to this annotation's task. */
    public function record(TaskHistory $history): int
    {
        if ($history->action_type !== 'Annotated' || blank($history->note)) {
            return 0;
        }

        $task = $history->task;
        $mails = MailRecord::query()
            ->where(function ($query) use ($task) {
                $query->where('task_id', $task->id)
                    ->orWhere('routing_task_id', $task->id)
                    ->orWhereHas('correspondence.recipients', fn ($recipient) => $recipient->where('task_id', $task->id));
            })
            ->whereNotNull('correspondence_id')
            ->get()
            ->unique('correspondence_id');

        if ($mails->isEmpty()) {
            return 0;
        }

        $originTitle = AnnotationTitle::find($history->annotation_origin_title_id);
        $recipientTitle = AnnotationTitle::find($history->annotation_recipient_title_id);
        $originUser = User::withTrashed()->find($history->annotation_origin_user_id);
        $recipientIds = $history->annotation_recipient_user_ids ?? [];
        $usersById = User::withTrashed()->whereKey($recipientIds)->get()->keyBy('id');
        $recipientUsers = collect($recipientIds)->map(fn ($id) => $usersById->get($id))->filter()->values();
        $author = User::withTrashed()->find($history->performed_by_user_id);
        $originOffice = $this->officeFromSnapshot($history->annotation_origin_snapshot)
            ?? $originTitle?->shorthand
            ?? $originUser?->officialOfficeName()
            ?? $history->performed_by_office_snapshot
            ?? $author?->officialOfficeName();
        $destinationOffice = $this->destinationOffice($history, $recipientTitle, $recipientUsers);
        $destinationUnitId = $recipientUsers->isNotEmpty()
            ? ($recipientUsers->count() === 1 ? $recipientUsers->first()->organizational_unit_id : null)
            : ($recipientTitle === null
                ? ($task->assigned_to_organizational_unit_id ?? $task->currentAssignee?->organizational_unit_id)
                : null);

        $created = 0;
        foreach ($mails as $mail) {
            $recipientRoute = $destinationOffice === null ? $this->mailRecipientRoute($task->id, $mail) : [];
            $resolvedDestination = $destinationOffice ?? $recipientRoute['office'] ?? null;
            $entry = CorrespondenceUpdate::firstOrCreate(
                ['correspondence_id' => $mail->correspondence_id, 'task_history_id' => $history->id],
                [
                    'task_id' => $task->id,
                    'type' => 'annotation',
                    'entry_method' => 'task_annotation',
                    'body' => $history->note,
                    'source_name_snapshot' => mb_substr((string) $originOffice, 0, 255) ?: null,
                    'source_annotation_title_id' => $originTitle?->id,
                    'source_user_id' => $originUser?->id,
                    'from_organizational_unit_id' => $originTitle === null
                        ? ($originUser?->organizational_unit_id ?? $author?->organizational_unit_id) : null,
                    'destination_office_snapshot' => mb_substr((string) $resolvedDestination, 0, 255) ?: null,
                    'destination_annotation_title_id' => $recipientTitle?->id ?? $recipientRoute['title_id'] ?? null,
                    'destination_user_id' => $recipientUsers->count() === 1 ? $recipientUsers->first()->id : ($recipientRoute['user_id'] ?? null),
                    'destination_department_id' => $recipientTitle === null && $recipientUsers->isEmpty()
                        ? ($task->assigned_to_department_id ?? $recipientRoute['department_id'] ?? null) : null,
                    'to_organizational_unit_id' => $destinationUnitId ?? $recipientRoute['organizational_unit_id'] ?? null,
                    'recipient_summary' => $recipientUsers->map(fn (User $recipient) => [
                        'type' => 'user',
                        'name' => $recipient->full_name,
                        'office' => $recipient->officialOfficeName(),
                    ])->values()->all(),
                    'performed_by_user_id' => $history->performed_by_user_id,
                    'performed_by_name_snapshot' => $history->performed_by_name_snapshot,
                    'performed_by_title_snapshot' => $history->performed_by_title_snapshot,
                    'performed_by_office_snapshot' => $history->performed_by_office_snapshot,
                    'performed_by_role_snapshot' => $history->performed_by_role,
                    'occurred_at' => $history->created_at,
                    'recorded_at' => $history->created_at,
                    'created_at' => $history->created_at,
                ],
            );
            if (! $entry->wasRecentlyCreated && blank($entry->destination_office_snapshot) && filled($resolvedDestination)) {
                // Repair an earlier incomplete backfill without rewriting an existing route.
                $entry->update([
                    'destination_office_snapshot' => mb_substr($resolvedDestination, 0, 255),
                    'destination_annotation_title_id' => $recipientTitle?->id ?? $recipientRoute['title_id'] ?? null,
                    'destination_user_id' => $recipientUsers->count() === 1 ? $recipientUsers->first()->id : ($recipientRoute['user_id'] ?? null),
                    'destination_department_id' => $recipientRoute['department_id'] ?? null,
                    'to_organizational_unit_id' => $destinationUnitId ?? $recipientRoute['organizational_unit_id'] ?? null,
                ]);
            }
            $created += (int) $entry->wasRecentlyCreated;
        }

        return $created;
    }

    /** @return array{office: ?string, title_id?: ?int, user_id?: ?int, department_id?: ?int, organizational_unit_id?: ?int} */
    private function mailRecipientRoute(int $taskId, MailRecord $mail): array
    {
        $recipients = CorrespondenceRecipient::query()
            ->where('correspondence_id', $mail->correspondence_id)
            ->where('task_id', $taskId)
            ->where('active', true)
            ->with(['organizationalUnit', 'department', 'user'])
            ->get();
        if ($recipients->isNotEmpty()) {
            $offices = $recipients->map(fn (CorrespondenceRecipient $recipient) => $recipient->office_snapshot
                ?? ($recipient->organizationalUnit ? $this->routingLabel->for($recipient->organizationalUnit) : null)
                ?? $recipient->department?->code
                ?? $recipient->user?->officialOfficeName()
                ?? $recipient->recipient_name_snapshot)->filter()->unique();

            return [
                'office' => $offices->implode('; '),
                'user_id' => $recipients->count() === 1 ? $recipients->first()->user_id : null,
                'department_id' => $recipients->count() === 1 ? $recipients->first()->department_id : null,
                'organizational_unit_id' => $recipients->count() === 1 ? $recipients->first()->organizational_unit_id : null,
            ];
        }

        $mail->loadMissing(['recipientAnnotationTitle', 'recipientStaffUser', 'recipientDepartment', 'correspondence.currentHolderOrganizationalUnit']);

        return [
            'office' => $mail->recipientAnnotationTitle?->shorthand
                ?? $mail->recipientStaffUser?->officialOfficeName()
                ?? $mail->recipientDepartment?->code
                ?? $mail->recipient_name
                ?? ($mail->correspondence?->currentHolderOrganizationalUnit
                    ? $this->routingLabel->for($mail->correspondence->currentHolderOrganizationalUnit) : null),
            'title_id' => $mail->recipient_annotation_title_id,
            'user_id' => $mail->recipient_staff_user_id,
            'department_id' => $mail->recipient_department_id,
            'organizational_unit_id' => $mail->recipient_annotation_title_id !== null
                || $mail->recipient_staff_user_id !== null
                || $mail->recipient_department_id !== null
                || filled($mail->recipient_name)
                    ? null : $mail->correspondence?->current_holder_organizational_unit_id,
        ];
    }

    private function destinationOffice(TaskHistory $history, ?AnnotationTitle $title, Collection $users): ?string
    {
        if ($users->isNotEmpty()) {
            $snapshots = collect(explode('; ', (string) $history->annotation_recipient_snapshot));

            return $users->map(fn (User $user, int $index) => $this->officeFromSnapshot($snapshots->get($index))
                ?? $user->officialOfficeName()
                ?? $user->officialTitle())->unique()->implode('; ');
        }
        if ($title !== null) {
            return $title->shorthand;
        }

        $task = $history->task;
        if ($task->assignedToOrganizationalUnit !== null) {
            return $this->routingLabel->for($task->assignedToOrganizationalUnit);
        }

        return $task->currentAssignee?->officialOfficeName()
            ?? $task->assignedTo?->officialOfficeName()
            ?? $task->assignedToDepartment?->code
            ?? $task->assignedToDepartment?->name;
    }

    private function officeFromSnapshot(?string $snapshot): ?string
    {
        if ($snapshot === null || ! str_contains($snapshot, ' · ')) {
            return null;
        }

        return trim((string) str($snapshot)->afterLast(' · ')) ?: null;
    }
}
