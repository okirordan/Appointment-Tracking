<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailRecord;
use App\Services\AuditLogger;
use App\Services\Mail\MailRecordPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CorrespondencePrintController extends Controller
{
    public function __invoke(
        Request $request,
        MailRecord $mail,
        MailRecordPresenter $presenter,
        AuditLogger $audit,
    ): View {
        $this->authorize('view', $mail);

        $record = $presenter->detail($mail);
        $printedAt = now();
        $printedBy = $request->user();

        $audit->log(
            'mail',
            "Generated printable correspondence record {$mail->register_number}",
            $printedBy,
            'MailRecord',
            $mail->id,
            ['correspondence_id' => $mail->correspondence_id, 'printed_at' => $printedAt->toIso8601String()],
        );

        return view('correspondence.print', compact('mail', 'record', 'printedAt', 'printedBy'));
    }
}
