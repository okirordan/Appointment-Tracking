<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\AssignOutgoingCorrespondenceRequest;
use App\Models\MailRecord;
use App\Services\Mail\MailRecordService;
use Illuminate\Http\RedirectResponse;

class OutgoingCorrespondenceAssignmentController extends Controller
{
    public function __construct(private MailRecordService $service) {}

    public function store(AssignOutgoingCorrespondenceRequest $request, MailRecord $mail): RedirectResponse
    {
        $task = $this->service->assignOutgoing(
            $request->user(),
            $mail,
            $request->safe()->except('attachments'),
            $request->file('attachments', []),
        );

        return redirect()->route('mail.show', $mail)
            ->with('success', "Assignment {$task->reference} created and linked to {$mail->register_number}.");
    }
}
