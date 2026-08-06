<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\ForwardCorrespondenceRequest;
use App\Models\MailRecord;
use App\Services\Mail\CorrespondenceForwardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MailAssignmentController extends Controller
{
    public function __construct(private CorrespondenceForwardingService $service) {}

    public function store(ForwardCorrespondenceRequest $request, MailRecord $mail): RedirectResponse
    {
        try {
            $data = $request->validated();
            $data['attachments'] = $request->file('attachments', []);
            $result = $this->service->forward($request->user(), $mail, $data, $request->file('attachments', []));
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

        $recipientNames = $result['forward']->recipients
            ->where('recipient_type', 'to')
            ->pluck('recipient_name_snapshot')
            ->filter()
            ->unique()
            ->values();
        $recipientSummary = $recipientNames->count() > 3
            ? $recipientNames->take(3)->implode(', ').' and '.($recipientNames->count() - 3).' more'
            : $recipientNames->implode(', ');
        $target = $recipientSummary === '' ? 'the selected recipients' : $recipientSummary;

        $message = $result['task'] === null
            ? "Correspondence {$mail->register_number} forwarded successfully to {$target} for information."
            : "Correspondence {$mail->register_number} forwarded successfully to {$target} with action required as {$result['task']->reference}.";

        return redirect()->route('mail.show', $mail)->with('success', $message);
    }
}
