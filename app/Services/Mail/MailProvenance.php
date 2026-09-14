<?php

namespace App\Services\Mail;

use App\Models\MailRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Presents origin separately from routing, including older outgoing copies. */
class MailProvenance
{
    public function __construct(private CrossDepartmentInteractionVisibility $visibility, private MailAccessScope $access) {}

    public function origin(MailRecord $mail): MailRecord
    {
        $seen = [];
        while (! isset($seen[$mail->id])) {
            $seen[$mail->id] = true;
            $parent = $mail->source_mail_record_id !== null ? $mail->sourceMailRecord : null;
            if ($parent === null && $mail->correspondence?->originating_mail_record_id !== $mail->id) {
                $parent = $mail->correspondence?->originatingMailRecord;
            }
            if ($parent === null || isset($seen[$parent->id])) {
                break;
            }
            $mail = $parent;
        }

        return $mail;
    }

    public function summary(MailRecord $mail, ?User $viewer = null): array
    {
        $origin = $this->origin($mail);
        [$recipients, $updates] = $this->visibleRecords($origin, $viewer);
        $primary = collect($recipients->all())->where('recipient_type', 'to');
        $latest = $primary->filter(fn ($r) => $r->forward !== null)
            ->sortByDesc(fn ($r) => [$r->forward->forwarded_at?->getTimestamp() ?? 0, $r->forward->id])->first()?->forward;
        $receivedBy = $origin->organizationalUnit?->name ?? $origin->department?->name ?? 'Receiving office not recorded';
        $through = $primary->map(fn ($r) => $this->from($r->forward))->filter()->prepend($receivedBy)->unique()->values();
        $frontier = $primary->where('active', true)->filter(function ($recipient) use ($primary) {
            // Retained access to an earlier hop does not mean the mail is still there.
            return ! $primary->contains(fn ($later) => $later->forward !== null
                && $this->sameOffice($later->forward, $recipient)
                && [$later->forward->forwarded_at?->getTimestamp(), $later->forward->id]
                    > [($recipient->forward?->forwarded_at ?? $recipient->added_at)?->getTimestamp(), $recipient->correspondence_forward_id ?? 0]);
        });
        $current = $frontier->map(fn ($r) => $this->to($r))->unique()->values();
        $handlers = collect();
        foreach ($frontier as $recipient) {
            $task = $recipient->task;
            if ($task === null || ($viewer !== null && ! $viewer->can('view', $task))) {
                continue;
            }
            $steps = $task->workflowSteps->where('is_current', true);
            if ($steps->isNotEmpty()) {
                $current = $current->reject(fn ($office) => $office === $this->to($recipient));
                foreach ($steps as $step) {
                    $current->push($step->recipient_office_snapshot ?? $step->recipient?->officialOfficeName() ?? $this->to($recipient));
                    $handlers->push($step->recipient_name_snapshot ?? $step->recipient?->full_name);
                }
            } elseif ($task->currentReviewer !== null) {
                $current = $current->reject(fn ($office) => $office === $this->to($recipient));
                $current->push($task->currentReviewer->officialOfficeName() ?? $this->to($recipient));
                $handlers->push($task->currentReviewer->full_name);
            }
        }
        $latestUpdate = $updates->whereNotNull('to_organizational_unit_id')->sortByDesc(fn ($u) => [$u->occurred_at?->getTimestamp(), $u->id])->first();
        if ($latestUpdate !== null && ($latest === null || $latestUpdate->occurred_at?->gt($latest->forwarded_at))) {
            $current = collect([$latestUpdate->toOrganizationalUnit?->name])->filter();
        }

        $legacy = $this->legacyRecords($origin, $viewer)->sortByDesc('dispatched_at')->first();
        if ($latest === null && $legacy !== null) {
            $current = collect([$legacy->routingTask?->assignedToDepartment?->name ?? $legacy->recipient_name])->filter();
        }
        if ($primary->isEmpty() && $legacy === null && $latestUpdate === null) {
            $current = collect([$receivedBy]);
        }
        if (in_array($origin->correspondence?->current_status?->value, ['closed', 'filed', 'withdrawn'], true)) {
            $current = collect();
            $handlers = collect();
        }

        return [
            'original_source' => $origin->sender_name ?: ($origin->external_source ?: 'Source not recorded'),
            'original_source_organisation' => $origin->sender_organisation,
            'original_addressee' => $origin->recipient_name ?: 'Addressee not recorded',
            'received_by' => $receivedBy,
            'received_through' => $through->all(),
            'current_locations' => $current->filter()->unique()->values()->all(),
            'current_handlers' => $handlers->filter()->unique()->values()->all(),
            'latest_forward' => $latest !== null ? [
                'from' => $this->from($latest),
                'to' => $primary->where('correspondence_forward_id', $latest->id)->map(fn ($r) => $this->to($r))->unique()->values()->all(),
                'by' => $latest->forwarded_by_name_snapshot ?? $latest->forwardedBy?->full_name ?? 'Officer not recorded',
                'forwarded_at' => $latest->forwarded_at?->toIso8601String(),
                'forwarded_at_label' => $latest->forwarded_at?->format('d/m/Y H:i'),
            ] : ($legacy === null ? null : [
                'from' => $legacy->organizationalUnit?->name ?? $legacy->department?->name ?? $legacy->sender_name,
                'to' => $current->values()->all(),
                'by' => $legacy->capturedBy?->full_name ?? 'Officer not recorded',
                'forwarded_at' => $legacy->dispatched_at?->toIso8601String(),
                'forwarded_at_label' => $legacy->dispatched_at?->format('d/m/Y H:i'),
            ]),
        ];
    }

    public function timeline(MailRecord $mail, ?User $viewer = null): array
    {
        $origin = $this->origin($mail);
        [$recipients, $updates] = $this->visibleRecords($origin, $viewer);
        $office = $origin->organizationalUnit?->name ?? $origin->department?->name ?? 'Receiving office not recorded';
        $events = collect([$this->event('origin-'.$origin->id, 'registered', $origin->received_date ?? $origin->sent_date ?? $origin->created_at,
            $origin->sender_name, $office, $origin->capturedBy?->full_name, null)]);
        if ($origin->received_date !== null) {
            $events = $events->map(fn ($event) => [...$event,
                'at_label' => $origin->received_date->format('d/m/Y').' (receipt date)',
                'instructions' => 'Registered on '.$origin->created_at?->format('d/m/Y H:i').'.',
            ]);
        }
        foreach ($recipients as $recipient) {
            $forward = $recipient->forward;
            $from = $this->from($forward) ?? $office;
            $to = $this->to($recipient);
            $by = $forward?->forwarded_by_name_snapshot ?? $forward?->forwardedBy?->full_name ?? $recipient->addedBy?->full_name;
            $events->push($this->event('forward-'.$recipient->id, $recipient->recipient_type === 'cc' ? 'copied' : 'forwarded',
                $forward?->forwarded_at ?? $recipient->added_at, $from, $to, $by, $forward?->instructions));
            if ($recipient->received_at !== null) {
                $events->push($this->event('receipt-'.$recipient->id, 'received', $recipient->received_at, $from, $to,
                    $recipient->receivedBy?->full_name, $recipient->received_by_user_id === null ? 'Receipt recorded by the system; no officer acknowledgement recorded.' : null));
            }
            if ($recipient->removed_at !== null) {
                $events->push($this->event('removed-'.$recipient->id, 'recipient_removed', $recipient->removed_at, $to, null, null, $recipient->removal_reason));
            }
        }
        foreach ($updates as $update) {
            // Routing is represented above; retain every other status change and annotation.
            if ($update->type === 'forwarded' && $recipients->contains('correspondence_forward_id', $update->correspondence_forward_id)) {
                continue;
            }
            $events->push($this->event('update-'.$update->id, $update->type, $update->occurred_at ?? $update->created_at,
                $update->fromOrganizationalUnit?->name ?? $update->performed_by_office_snapshot,
                $update->toOrganizationalUnit?->name, $update->performed_by_name_snapshot, $update->body));
        }
        foreach ($this->legacyRecords($origin, $viewer) as $legacy) {
            $events->push($this->event('legacy-'.$legacy->id, 'forwarded', $legacy->dispatched_at ?? $legacy->created_at,
                $legacy->organizationalUnit?->name ?? $legacy->sender_name, $legacy->recipient_name,
                $legacy->capturedBy?->full_name, $legacy->details));
        }
        $tasks = collect([$origin->task, $origin->routingTask])
            ->merge($recipients->map(fn ($r) => $r->task))
            ->merge($origin->forwardedRecords->map(fn ($r) => $r->routingTask))->filter()->unique('id');
        foreach ($tasks as $task) {
            if ($viewer !== null && ! $viewer->can('view', $task)) {
                continue;
            }
            foreach ($task->workflowSteps as $step) {
                $events->push($this->event('assignment-step-'.$step->id, 'assigned', $step->assigned_at,
                    $step->sender_office_snapshot ?? $step->sender?->officialOfficeName(),
                    $step->recipient_name_snapshot ?? $step->recipient?->full_name,
                    $step->sender_name_snapshot ?? $step->sender?->full_name, $step->instructions));
            }
            foreach ($task->histories as $history) {
                $events->push($this->event('task-history-'.$history->id, $history->action_type, $history->created_at,
                    $history->performed_by_office_snapshot, $history->annotation_recipient_snapshot,
                    $history->performed_by_name_snapshot, $history->note));
            }
        }

        return $events->sortBy('at')->values()->all();
    }

    private function visibleRecords(MailRecord $mail, ?User $viewer): array
    {
        $mail->loadMissing(['correspondence.recipients.forward.forwardedBy', 'correspondence.recipients.forward.fromOrganizationalUnit',
            'correspondence.recipients.organizationalUnit', 'correspondence.recipients.department', 'correspondence.recipients.user',
            'correspondence.updates', 'forwardedRecords.routingTask', 'organizationalUnit', 'department']);
        $updates = $mail->correspondence?->updates ?? collect();
        $recipients = $mail->correspondence?->recipients ?? collect();
        if ($viewer === null) {
            return [$recipients, $updates];
        }
        $general = $this->access->allowsWithoutHistoricalInteractions($viewer, $mail);
        $cross = $updates->where('entry_method', 'ps_cross_department');
        $visibleIds = $cross->isEmpty() ? collect() : $this->visibility->visible($mail->correspondence, $viewer)->pluck('id');
        $crossForwardIds = $cross->pluck('correspondence_forward_id')->filter();
        $visibleForwardIds = $cross->whereIn('id', $visibleIds)->pluck('correspondence_forward_id')->filter();

        return [
            $recipients->filter(fn ($r) => $crossForwardIds->contains($r->correspondence_forward_id)
                ? $visibleForwardIds->contains($r->correspondence_forward_id) : $general),
            $updates->filter(fn ($u) => $u->entry_method === 'ps_cross_department' ? $visibleIds->contains($u->id) : $general),
        ];
    }

    private function legacyRecords(MailRecord $origin, ?User $viewer): Collection
    {
        if ($viewer !== null && ! $this->access->allowsWithoutHistoricalInteractions($viewer, $origin)) {
            return collect();
        }

        return $origin->forwardedRecords->filter(fn ($record) => $viewer === null || $viewer->can('view', $record));
    }

    private function from($forward): ?string
    {
        return $forward?->from_office_snapshot ?? $forward?->fromOrganizationalUnit?->name ?? $forward?->origin_title_snapshot;
    }

    private function sameOffice($forward, $recipient): bool
    {
        if ($recipient->organizational_unit_id !== null) {
            return $forward->from_organizational_unit_id === $recipient->organizational_unit_id;
        }
        if ($recipient->department_id !== null && $forward->fromOrganizationalUnit?->department_id !== null) {
            return $forward->fromOrganizationalUnit->department_id === $recipient->department_id;
        }

        return $this->from($forward) === $this->to($recipient);
    }

    private function to($recipient): string
    {
        return $recipient->office_snapshot ?? $recipient->organizationalUnit?->name ?? $recipient->department?->name
            ?? $recipient->recipient_name_snapshot ?? 'Recipient not recorded';
    }

    private function event(string $id, string $type, $at, ?string $from, ?string $to, ?string $by, ?string $instructions): array
    {
        $date = $at === null ? null : Carbon::parse($at);

        return ['id' => $id, 'type' => $type, 'at' => $date?->toIso8601String(), 'at_label' => $date?->format('d/m/Y H:i'),
            'from' => $from, 'to' => $to, 'by' => $by, 'instructions' => $instructions];
    }
}
