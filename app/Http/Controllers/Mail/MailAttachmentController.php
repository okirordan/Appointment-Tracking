<?php

namespace App\Http\Controllers\Mail;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\MailAttachment;
use App\Services\AuditLogger;
use App\Services\Tasks\EvidencePreviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MailAttachmentController extends Controller
{
    public function __construct(private AuditLogger $audit, private EvidencePreviewService $previews) {}

    public function download(Request $request, MailAttachment $attachment): StreamedResponse
    {
        abort_unless($this->canAccess($request, $attachment), 403, 'You do not have permission to view this correspondence attachment.');
        abort_unless(Storage::disk('mail')->exists($attachment->storage_key), 404);

        $this->audit->log('mail', "Downloaded {$attachment->original_filename} from {$attachment->mailRecord->register_number}", $request->user(), 'MailAttachment', $attachment->id);

        return Storage::disk('mail')->download($attachment->storage_key, $attachment->original_filename);
    }

    public function preview(Request $request, MailAttachment $attachment): BinaryFileResponse|Response
    {
        abort_unless($this->canAccess($request, $attachment), 403, 'You do not have permission to view this correspondence attachment.');
        abort_unless(Storage::disk('mail')->exists($attachment->storage_key), 404);
        $kind = $attachment->previewKind();
        abort_if($kind === 'none', 415, 'This attachment type cannot be previewed.');

        $path = Storage::disk('mail')->path($attachment->storage_key);
        $this->audit->log('mail', "Previewed {$attachment->original_filename} from {$attachment->mailRecord->register_number}", $request->user(), 'MailAttachment', $attachment->id);

        if ($kind === 'document') {
            return response($this->previews->documentHtml($path, $attachment->original_filename), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->file($path, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $attachment->original_filename, 'mail-attachment'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function canAccess(Request $request, MailAttachment $attachment): bool
    {
        if ($request->user()->role === Role::Sysadmin) {
            return false;
        }

        $mail = $attachment->mailRecord;

        return $request->user()->can('view', $mail)
            || ($mail->task !== null && $request->user()->can('view', $mail->task));
    }
}
