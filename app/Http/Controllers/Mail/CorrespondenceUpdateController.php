<?php

namespace App\Http\Controllers\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreCorrespondenceUpdateRequest;
use App\Models\AnnotationTitle;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceUpdate;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Mail\BasicMailDirectory;
use App\Services\Mail\CorrespondenceForwardingService;
use App\Services\Mail\CorrespondenceOfficeDirectory;
use App\Services\Mail\MailboxScope;
use App\Services\Mail\MailViewContext;
use App\Services\Mail\RecipientSearchService;
use App\Services\NotificationService;
use App\Services\Tasks\AssignmentTargetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CorrespondenceUpdateController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private AssignmentTargetService $targets,
        private AuditLogger $audit,
        private CorrespondenceOfficeDirectory $offices,
    ) {}

    public function store(StoreCorrespondenceUpdateRequest $request, MailRecord $mail): RedirectResponse
    {
        $data = $request->validated();
        $files = $request->file('attachments', []);
        $basic = ($data['entry_method'] ?? '') === 'basic_correspondence';
        $incoming = $basic && $mail->isIncoming() && app(MailboxScope::class)
            ->incoming(MailRecord::query(), $request->user())->whereKey($mail->id)->exists();
        if ($incoming) {
            $this->authorize('assign', $mail);
            $directory = app(BasicMailDirectory::class);
            $party = $directory->resolve($data['destination_key'] ?? null, 'destination_key');
            if ($party instanceof User && ! app(RecipientSearchService::class)->isAssignable($request->user(), $party)) {
                throw ValidationException::withMessages(['destination_key' => 'This officer is outside your authorised forwarding scope.']);
            }
            $routing = match (true) {
                $party instanceof AnnotationTitle => ['recipient_title_id' => $party->id],
                $party instanceof Department => ['target_type' => 'department', 'target_department_id' => $party->id],
                $party instanceof User => ['assigned_to_user_id' => $party->id],
                default => ['external_recipients' => [['name' => trim($data['destination_office_snapshot']), 'recipient_type' => 'to']]],
            };
            app(CorrespondenceForwardingService::class)->forward($request->user(), $mail, [
                ...$routing, 'action_required' => false, 'instructions' => trim($data['body']), 'forwarded_date' => $data['recorded_date'],
            ], $files, ['entry_method' => 'basic_correspondence', 'basic_destination_input' => $data]);

            return redirect()->route('mail.show', ['mail' => $mail, ...MailViewContext::parameters($request), 'category' => 'outgoing', 'section' => 'correspondences'])
                ->with('success', 'Correspondence saved. Mail moved to Outgoing.');
        }
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
                    'entry_method' => $data['entry_method'] ?? 'normal',
                    'body' => trim($data['body']),
                    ...(($data['entry_method'] ?? '') === 'basic_correspondence'
                        ? app(BasicMailDirectory::class)->correspondenceAttributes($data, $request->user()) : []),
                    'occurred_at' => isset($data['recorded_date'])
                        ? Carbon::createFromFormat('Y-m-d', $data['recorded_date'])->startOfDay() : now(),
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

        return redirect()->route('mail.show', ['mail' => $mail, ...MailViewContext::parameters($request)])->with('success', 'Correspondence update added.');
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
