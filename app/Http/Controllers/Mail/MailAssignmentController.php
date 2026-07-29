<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\AssignMailRequest;
use App\Models\MailRecord;
use App\Services\Mail\MailRecordService;
use Illuminate\Http\RedirectResponse;

class MailAssignmentController extends Controller
{
    public function __construct(private MailRecordService $service) {}

    public function store(AssignMailRequest $request, MailRecord $mail): RedirectResponse
    {
        $task = $this->service->assign($request->user(), $mail, $request->validated());

        return redirect()->route('tasks.show', $task)
            ->with('success', "Incoming mail {$mail->register_number} assigned as {$task->reference}.");
    }
}
