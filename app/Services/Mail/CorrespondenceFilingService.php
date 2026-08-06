<?php

namespace App\Services\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Enums\CorrespondenceStatus;
use App\Models\Correspondence;
use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrespondenceFilingService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * File an incoming correspondence within its receiving office without
     * creating an assignment or task. The full record, attachments and audit
     * trail are preserved and the item leaves the active incoming queue.
     *
     * @param  array<string, mixed>  $data
     */
    public function file(User $actor, MailRecord $mail, array $data): Correspondence
    {
        $correspondence = DB::transaction(function () use ($actor, $mail, $data) {
            $locked = MailRecord::query()->lockForUpdate()->findOrFail($mail->id);
            if (! $locked->isIncoming()) {
                throw ValidationException::withMessages(['mail' => 'Only incoming correspondence can be filed.']);
            }

            $correspondence = $this->ensureCorrespondence($locked);
            if (! $correspondence->current_status->isActiveIncoming()) {
                throw ValidationException::withMessages(['mail' => 'Only active incoming correspondence can be filed. This item has already been forwarded, filed or closed.']);
            }

            $before = $correspondence->current_status;
            $note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;
            $category = filled($data['filing_category'] ?? null) ? trim((string) $data['filing_category']) : null;

            $correspondence->update([
                'current_status' => CorrespondenceLifecycleStatus::Filed,
                'last_activity_at' => now(),
                'filed_at' => now(),
                'filed_by_user_id' => $actor->id,
                'filed_organizational_unit_id' => $locked->organizational_unit_id,
                'filed_department_id' => $locked->department_id,
                'filing_category' => $category,
                'filing_note' => $note,
                'lock_version' => $correspondence->lock_version + 1,
            ]);
            $locked->update([
                'status' => CorrespondenceStatus::Filed,
                'last_processed_by_user_id' => $actor->id,
            ]);

            CorrespondenceUpdate::create([
                'correspondence_id' => $correspondence->id,
                'type' => 'filed',
                'body' => $note,
                'status_from' => $before->value,
                'status_to' => CorrespondenceLifecycleStatus::Filed->value,
                'performed_by_user_id' => $actor->id,
                'performed_by_name_snapshot' => $actor->full_name,
                'performed_by_title_snapshot' => $actor->title,
                'performed_by_role_snapshot' => $actor->roleName(),
                'created_at' => now(),
            ]);

            $this->audit->log('mail', "Filed correspondence {$locked->register_number}", $actor, 'MailRecord', $locked->id, [
                'correspondence_id' => $correspondence->id,
                'before_status' => $before->value,
                'after_status' => CorrespondenceLifecycleStatus::Filed->value,
                'filed_organizational_unit_id' => $locked->organizational_unit_id,
                'filed_department_id' => $locked->department_id,
                'filing_category' => $category,
                'filing_note' => $note,
            ]);

            return $correspondence;
        });

        Cache::forget("ats:mail:stats:{$actor->id}");

        return $correspondence;
    }

    /**
     * Return a filed correspondence to the active incoming queue so it can be
     * reviewed and forwarded again. The filing history stays in the timeline.
     *
     * @param  array<string, mixed>  $data
     */
    public function reopen(User $actor, MailRecord $mail, array $data = []): Correspondence
    {
        $correspondence = DB::transaction(function () use ($actor, $mail, $data) {
            $locked = MailRecord::query()->lockForUpdate()->findOrFail($mail->id);
            $correspondence = $locked->correspondence_id === null
                ? null
                : Correspondence::query()->lockForUpdate()->find($locked->correspondence_id);
            if ($correspondence === null || $correspondence->current_status !== CorrespondenceLifecycleStatus::Filed) {
                throw ValidationException::withMessages(['mail' => 'Only filed correspondence can be reopened.']);
            }

            $note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;

            $correspondence->update([
                'current_status' => CorrespondenceLifecycleStatus::Incoming,
                'last_activity_at' => now(),
                'lock_version' => $correspondence->lock_version + 1,
            ]);
            $locked->update([
                'status' => CorrespondenceStatus::Registered,
                'last_processed_by_user_id' => $actor->id,
            ]);

            CorrespondenceUpdate::create([
                'correspondence_id' => $correspondence->id,
                'type' => 'reopened',
                'body' => $note,
                'status_from' => CorrespondenceLifecycleStatus::Filed->value,
                'status_to' => CorrespondenceLifecycleStatus::Incoming->value,
                'performed_by_user_id' => $actor->id,
                'performed_by_name_snapshot' => $actor->full_name,
                'performed_by_title_snapshot' => $actor->title,
                'performed_by_role_snapshot' => $actor->roleName(),
                'created_at' => now(),
            ]);

            $this->audit->log('mail', "Reopened filed correspondence {$locked->register_number}", $actor, 'MailRecord', $locked->id, [
                'correspondence_id' => $correspondence->id,
                'before_status' => CorrespondenceLifecycleStatus::Filed->value,
                'after_status' => CorrespondenceLifecycleStatus::Incoming->value,
                'note' => $note,
            ]);

            return $correspondence;
        });

        Cache::forget("ats:mail:stats:{$actor->id}");

        return $correspondence;
    }

    private function ensureCorrespondence(MailRecord $mail): Correspondence
    {
        if ($mail->correspondence_id !== null) {
            return Correspondence::query()->lockForUpdate()->findOrFail($mail->correspondence_id);
        }

        $correspondence = Correspondence::create([
            'canonical_reference' => $mail->register_number,
            'origin_direction' => $mail->direction,
            'originating_mail_record_id' => $mail->id,
            'office_supervisor_user_id' => $mail->office_supervisor_user_id,
            'organizational_unit_id' => $mail->organizational_unit_id,
            'department_id' => $mail->department_id,
            'confidentiality' => $mail->confidentiality,
            'current_status' => CorrespondenceLifecycleStatus::Incoming,
            'last_activity_at' => $mail->updated_at ?? now(),
        ]);
        $mail->update(['correspondence_id' => $correspondence->id]);

        return $correspondence;
    }
}
