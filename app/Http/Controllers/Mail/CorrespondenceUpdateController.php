<?php

namespace App\Http\Controllers\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreCorrespondenceUpdateRequest;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceUpdate;
use App\Models\MailRecord;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\Tasks\AssignmentTargetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CorrespondenceUpdateController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private AssignmentTargetService $targets,
        private AuditLogger $audit,
    ) {}

    public function store(StoreCorrespondenceUpdateRequest $request, MailRecord $mail): RedirectResponse
    {
        $data = $request->validated();
        $files = $request->file('attachments', []);
        $storedKeys = [];

        try {
            $update = DB::transaction(function () use ($request, $mail, $data, $files, &$storedKeys) {
                $correspondence = $mail->correspondence()->lockForUpdate()->firstOrFail();
                $before = $correspondence->current_status;
                $after = $data['type'] === 'response' ? CorrespondenceLifecycleStatus::Responded : $before;
                $entry = CorrespondenceUpdate::create([
                    'correspondence_id' => $correspondence->id,
                    'task_id' => $mail->task_id,
                    'type' => $data['type'],
                    'body' => trim($data['body']),
                    'status_from' => $before->value,
                    'status_to' => $after->value,
                    'performed_by_user_id' => $request->user()->id,
                    'performed_by_name_snapshot' => $request->user()->full_name,
                    'performed_by_title_snapshot' => $request->user()->title,
                    'performed_by_role_snapshot' => $request->user()->roleName(),
                    'created_at' => now(),
                ]);

                foreach ($files as $file) {
                    $key = $file->store("correspondence/{$correspondence->id}", ['disk' => 'mail']);
                    abort_if($key === false, 500, "Upload failed for {$file->getClientOriginalName()}.");
                    $storedKeys[] = $key;
                    CorrespondenceAttachment::create([
                        'correspondence_id' => $correspondence->id,
                        'correspondence_update_id' => $entry->id,
                        'version_group' => (string) Str::uuid(),
                        'version_number' => 1,
                        'status' => 'active',
                        'original_filename' => $file->getClientOriginalName(),
                        'storage_key' => $key,
                        'mime_type' => (string) $file->getMimeType(),
                        'size_bytes' => $file->getSize(),
                        'checksum' => hash_file('sha256', $file->getRealPath()),
                        'uploaded_by_user_id' => $request->user()->id,
                        'uploaded_at' => now(),
                    ]);
                }

                $correspondence->update([
                    'current_status' => $after,
                    'last_activity_at' => now(),
                    'lock_version' => $correspondence->lock_version + 1,
                ]);

                return $entry;
            });
        } catch (\Throwable $exception) {
            foreach ($storedKeys as $key) {
                Storage::disk('mail')->delete($key);
            }
            throw $exception;
        }

        $this->audit->log('mail', "Added {$data['type']} to correspondence {$mail->register_number}", $request->user(), 'CorrespondenceUpdate', $update->id, [
            'correspondence_id' => $mail->correspondence_id,
            'attachments' => count($files),
        ]);
        $this->notifyParticipants($request->user()->id, $mail, $update);

        return redirect()->route('mail.show', $mail)->with('success', 'Correspondence update added.');
    }

    private function notifyParticipants(int $actorId, MailRecord $mail, CorrespondenceUpdate $update): void
    {
        $correspondence = $mail->correspondence;
        $users = collect();
        foreach ($correspondence->recipients()->where('active', true)->get() as $recipient) {
            $users = $users->concat(match ($recipient->target_type) {
                'office' => $recipient->organizational_unit_id === null ? collect() : $this->targets->officeMembers($recipient->organizational_unit_id),
                'department' => $recipient->department_id === null ? collect() : $this->targets->departmentMembers($recipient->department_id),
                default => collect([$recipient->user])->filter(),
            });
        }

        foreach ($users->unique('id') as $user) {
            if ($user->id === $actorId) {
                continue;
            }
            $this->notifications->notify(
                $user,
                'correspondence_update',
                "New correspondence update: {$mail->subject}",
                Str::limit($update->body, 180),
                null,
                $mail,
                "correspondence.update.{$update->id}.{$user->id}",
                'correspondence_updates',
                $correspondence->confidentiality !== 'normal',
            );
        }
    }
}
