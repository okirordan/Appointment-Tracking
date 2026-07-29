<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\EvidenceAttachment;
use App\Services\AuditLogger;
use App\Services\Tasks\EvidencePreviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceController extends Controller
{
    public function __construct(private AuditLogger $audit, private EvidencePreviewService $previews) {}

    /**
     * Evidence is served only through this authorised action from the
     * private disk (EVID-004/005) — never a public static path.
     */
    public function download(Request $request, EvidenceAttachment $evidence): StreamedResponse|RedirectResponse
    {
        $this->authorize('downloadEvidence', $evidence->task);

        if ($evidence->isLink()) {
            abort_unless(is_string($evidence->external_url) && $evidence->external_url !== '', 404);
            $this->audit->log('task', "Opened evidence link from {$evidence->task->reference}",
                $request->user(), 'EvidenceAttachment', $evidence->id);

            return redirect()->away($evidence->external_url);
        }

        abort_unless(Storage::disk('evidence')->exists($evidence->storage_key), 404);

        $this->audit->log('task', "Downloaded evidence {$evidence->original_filename} from {$evidence->task->reference}",
            $request->user(), 'EvidenceAttachment', $evidence->id);

        return Storage::disk('evidence')->download($evidence->storage_key, $evidence->original_filename);
    }

    public function preview(Request $request, EvidenceAttachment $evidence): BinaryFileResponse|Response|RedirectResponse
    {
        $this->authorize('downloadEvidence', $evidence->task);

        if ($evidence->isLink()) {
            abort_unless(is_string($evidence->external_url) && $evidence->external_url !== '', 404);

            return redirect()->away($evidence->external_url);
        }

        abort_unless(Storage::disk('evidence')->exists($evidence->storage_key), 404);
        $kind = $evidence->previewKind();
        abort_if($kind === 'none', 415, 'This evidence type cannot be previewed.');

        $this->audit->log('task', "Previewed evidence {$evidence->original_filename} from {$evidence->task->reference}",
            $request->user(), 'EvidenceAttachment', $evidence->id);

        $path = Storage::disk('evidence')->path($evidence->storage_key);
        if ($kind === 'document') {
            return response($this->previews->documentHtml($path, $evidence->original_filename), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->file($path, [
            'Content-Type' => $evidence->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $evidence->original_filename, 'evidence'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
