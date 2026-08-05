<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\AssignMailRequest;
use App\Models\MailRecord;
use App\Services\Mail\MailRecordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MailAssignmentController extends Controller
{
    public function __construct(private MailRecordService $service) {}

    public function store(AssignMailRequest $request, MailRecord $mail): RedirectResponse
    {
        try {
            $data = $request->validated();
            $data['attachments'] = $request->file('attachments', []);
            $task = $this->service->assign($request->user(), $mail, $data);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Incoming correspondence forwarding failed', [
                'mail_record_id' => $mail->id,
                'actor_user_id' => $request->user()->id,
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return back()
                ->withInput()
                ->with('error', 'The assignment could not be created. Your entries have been preserved; please try again or contact support.');
        }

        return redirect()->route('tasks.show', $task)
            ->with('success', "Incoming mail {$mail->register_number} was forwarded and assigned as {$task->reference}.");
    }
}
