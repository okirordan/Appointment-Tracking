<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\CorrespondenceAttachment;
use App\Models\CorrespondenceUpdate;
use App\Services\AuditLogger;
use App\Services\Tasks\EvidencePreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorrespondenceAttachmentController extends Controller
{
    public function __construct(private AuditLogger $audit, private EvidencePreviewService $previews) {}

    public function download(Request $request, CorrespondenceAttachment $attachment): StreamedResponse
    {
        $mail = $attachment->correspondence->mailRecords()->where('id', $attachment->correspondence->originating_mail_record_id)->first()
            ?? $attachment->correspondence->mailRecords()->firstOrFail();
        abort_unless($request->user()->can('view', $mail), 403, 'You do not have permission to view this correspondence attachment.');
        abort_unless($attachment->status !== 'removed' && Storage::disk('mail')->exists($attachment->storage_key), 404);

        $this->audit->log('mail', "Downloaded correspondence attachment {$attachment->original_filename}", $request->user(), 'CorrespondenceAttachment', $attachment->id);

        return Storage::disk('mail')->download($attachment->storage_key, $attachment->original_filename);
    }

    public function preview(Request $request, CorrespondenceAttachment $attachment): BinaryFileResponse|Response
    {
        $mail = $attachment->correspondence->mailRecords()->where('id', $attachment->correspondence->originating_mail_record_id)->first()
            ?? $attachment->correspondence->mailRecords()->firstOrFail();
        abort_unless($request->user()->can('view', $mail), 403, 'You do not have permission to view this correspondence attachment.');
        abort_unless($attachment->status !== 'removed' && Storage::disk('mail')->exists($attachment->storage_key), 404);
        abort_if($attachment->previewKind() === 'none', 415, 'This attachment type cannot be previewed.');

        $this->audit->log('mail', "Previewed correspondence attachment {$attachment->original_filename}", $request->user(), 'CorrespondenceAttachment', $attachment->id);
        $path = Storage::disk('mail')->path($attachment->storage_key);
        if ($attachment->previewKind() === 'document') {
            return response($this->previews->documentHtml($path, $attachment->original_filename), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->file($path, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $attachment->original_filename, 'correspondence-attachment'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function replace(Request $request, CorrespondenceAttachment $attachment): RedirectResponse
    {
        $mail = $attachment->correspondence->originatingMailRecord()->firstOrFail();
        $this->authorize('update', $mail);
        $data = $request->validate([
            'replacement' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,mp4,webm'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        abort_unless($attachment->status === 'active', 409, 'Only the active attachment version can be replaced.');

        $file = $request->file('replacement');
        $key = $file->store("correspondence/{$attachment->correspondence_id}", ['disk' => 'mail']);
        abort_if($key === false, 500, 'The replacement file could not be stored.');

        try {
            $replacement = DB::transaction(function () use ($request, $attachment, $file, $key, $data) {
                $current = CorrespondenceAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
                abort_unless($current->status === 'active', 409, 'This attachment was already changed.');
                $correspondence = $current->correspondence()->lockForUpdate()->firstOrFail();
                $update = CorrespondenceUpdate::create([
                    'correspondence_id' => $correspondence->id,
                    'type' => 'attachment_replaced',
                    'body' => "Replaced {$current->original_filename} with {$file->getClientOriginalName()}. Reason: ".trim($data['reason']),
                    'status_from' => $correspondence->current_status->value,
                    'status_to' => $correspondence->current_status->value,
                    'performed_by_user_id' => $request->user()->id,
                    'performed_by_name_snapshot' => $request->user()->full_name,
                    'performed_by_title_snapshot' => $request->user()->title,
                    'performed_by_role_snapshot' => $request->user()->roleName(),
                    'created_at' => now(),
                ]);
                $current->update([
                    'status' => 'superseded',
                    'removed_by_user_id' => $request->user()->id,
                    'removed_at' => now(),
                    'removal_reason' => trim($data['reason']),
                ]);
                $newVersion = CorrespondenceAttachment::create([
                    'correspondence_id' => $correspondence->id,
                    'correspondence_update_id' => $update->id,
                    'version_group' => $current->version_group,
                    'version_number' => $current->version_number + 1,
                    'supersedes_attachment_id' => $current->id,
                    'status' => 'active',
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_key' => $key,
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'checksum' => hash_file('sha256', $file->getRealPath()),
                    'uploaded_by_user_id' => $request->user()->id,
                    'uploaded_at' => now(),
                ]);
                $correspondence->update([
                    'last_activity_at' => now(),
                    'lock_version' => $correspondence->lock_version + 1,
                ]);

                return $newVersion;
            });
        } catch (\Throwable $exception) {
            Storage::disk('mail')->delete($key);
            throw $exception;
        }

        $this->audit->log('mail', "Replaced correspondence attachment {$attachment->original_filename}", $request->user(), 'CorrespondenceAttachment', $replacement->id, [
            'superseded_attachment_id' => $attachment->id,
            'version_number' => $replacement->version_number,
            'reason' => trim($data['reason']),
        ]);

        return redirect()->route('mail.show', $mail)->with('success', 'Attachment replaced. The previous version remains in the audit history.');
    }

    public function destroy(Request $request, CorrespondenceAttachment $attachment): RedirectResponse
    {
        $mail = $attachment->correspondence->originatingMailRecord()->firstOrFail();
        $this->authorize('update', $mail);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        abort_unless($attachment->status === 'active', 409, 'Only an active attachment can be removed.');

        DB::transaction(function () use ($request, $attachment, $data): void {
            $current = CorrespondenceAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
            abort_unless($current->status === 'active', 409, 'This attachment was already changed.');
            $correspondence = $current->correspondence()->lockForUpdate()->firstOrFail();
            $current->update([
                'status' => 'removed',
                'removed_by_user_id' => $request->user()->id,
                'removed_at' => now(),
                'removal_reason' => trim($data['reason']),
            ]);
            CorrespondenceUpdate::create([
                'correspondence_id' => $correspondence->id,
                'type' => 'attachment_removed',
                'body' => "Removed {$current->original_filename}. Reason: ".trim($data['reason']),
                'status_from' => $correspondence->current_status->value,
                'status_to' => $correspondence->current_status->value,
                'performed_by_user_id' => $request->user()->id,
                'performed_by_name_snapshot' => $request->user()->full_name,
                'performed_by_title_snapshot' => $request->user()->title,
                'performed_by_role_snapshot' => $request->user()->roleName(),
                'created_at' => now(),
            ]);
            $correspondence->update([
                'last_activity_at' => now(),
                'lock_version' => $correspondence->lock_version + 1,
            ]);
        });

        $this->audit->log('mail', "Removed correspondence attachment {$attachment->original_filename}", $request->user(), 'CorrespondenceAttachment', $attachment->id, [
            'reason' => trim($data['reason']),
            'storage_retained' => true,
        ]);

        return redirect()->route('mail.show', $mail)->with('success', 'Attachment removed from active view. Its file and audit history were retained.');
    }
}
