<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\RecordPsDepartmentInteractionRequest;
use App\Models\MailRecord;
use App\Services\Mail\PsOfficeDepartmentInteractionService;
use Illuminate\Http\RedirectResponse;

class PsDepartmentInteractionController extends Controller
{
    public function __construct(private PsOfficeDepartmentInteractionService $service) {}

    public function store(RecordPsDepartmentInteractionRequest $request, MailRecord $mail): RedirectResponse
    {
        $result = $this->service->record(
            $request->user(),
            $mail,
            $request->validated(),
            $request->file('attachments', []),
        );
        $message = "Department interaction recorded on {$mail->register_number}.";
        if ($result['task'] !== null) {
            $message .= " Assignment {$result['task']->reference} was created.";
        }

        return redirect()->route('mail.show', $mail)->with('success', $message);
    }
}
