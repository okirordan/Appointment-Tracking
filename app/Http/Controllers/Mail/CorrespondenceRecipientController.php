<?php

namespace App\Http\Controllers\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Http\Controllers\Controller;
use App\Models\CorrespondenceRecipient;
use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrespondenceRecipientController extends Controller
{
    public function destroy(
        Request $request,
        MailRecord $mail,
        CorrespondenceRecipient $recipient,
        AuditLogger $audit,
        NotificationService $notifications,
    ): RedirectResponse {
        $this->authorize('assign', $mail);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        if ($recipient->correspondence_id !== $mail->correspondence_id || ! $recipient->active) {
            abort(404);
        }
        if ($recipient->purpose === 'action_required' && $recipient->task_id !== null) {
            throw ValidationException::withMessages([
                'reason' => 'Use the assignment withdrawal action for a recipient who has an active task.',
            ]);
        }

        $actor = $request->user();
        $removedUser = $recipient->user;
        $after = DB::transaction(function () use ($actor, $mail, $recipient, $data): CorrespondenceLifecycleStatus {
            $locked = CorrespondenceRecipient::query()->lockForUpdate()->findOrFail($recipient->id);
            $correspondence = $mail->correspondence()->lockForUpdate()->firstOrFail();
            $before = $correspondence->current_status;

            $locked->update([
                'active' => false,
                'removed_by_user_id' => $actor->id,
                'removed_at' => now(),
                'removal_reason' => trim($data['reason']),
            ]);
            $after = $correspondence->recipients()->where('active', true)->exists()
                ? $before
                : CorrespondenceLifecycleStatus::Withdrawn;
            $correspondence->update([
                'current_status' => $after,
                'last_activity_at' => now(),
                'withdrawn_at' => $after === CorrespondenceLifecycleStatus::Withdrawn ? now() : null,
                'lock_version' => $correspondence->lock_version + 1,
            ]);
            CorrespondenceUpdate::create([
                'correspondence_id' => $correspondence->id,
                'type' => 'recipient_removed',
                'body' => "Removed {$locked->recipient_name_snapshot}. Reason: ".trim($data['reason']),
                'status_from' => $before->value,
                'status_to' => $after->value,
                'recipient_summary' => [[
                    'type' => $locked->recipient_type,
                    'purpose' => $locked->purpose,
                    'name' => $locked->recipient_name_snapshot,
                ]],
                'performed_by_user_id' => $actor->id,
                'performed_by_name_snapshot' => $actor->full_name,
                'performed_by_title_snapshot' => $actor->title,
                'performed_by_role_snapshot' => $actor->roleName(),
                'created_at' => now(),
            ]);

            return $after;
        });

        $audit->log('mail', "Removed correspondence recipient {$recipient->recipient_name_snapshot}", $actor, 'MailRecord', $mail->id, [
            'correspondence_id' => $mail->correspondence_id,
            'recipient_id' => $recipient->id,
            'reason' => trim($data['reason']),
            'after_status' => $after->value,
        ]);
        if ($removedUser !== null && $removedUser->id !== $actor->id) {
            $notifications->notify(
                $removedUser,
                'correspondence_recipient_removed',
                "You were removed from correspondence {$mail->register_number}",
                'Reason: '.trim($data['reason']),
                null,
                $mail,
            );
        }
        Cache::forget("ats:mail:stats:{$actor->id}");

        return redirect()->route('mail.show', $mail)->with('success', 'Recipient removed. The original recipient history was retained.');
    }
}
