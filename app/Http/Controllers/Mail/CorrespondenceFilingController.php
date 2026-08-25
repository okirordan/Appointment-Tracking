<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\FileCorrespondenceRequest;
use App\Models\MailRecord;
use App\Services\Mail\CorrespondenceFilingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CorrespondenceFilingController extends Controller
{
    public function __construct(private CorrespondenceFilingService $service) {}

    public function store(FileCorrespondenceRequest $request, MailRecord $mail): RedirectResponse
    {
        $this->service->file($request->user(), $mail, $request->validated());

        $office = $mail->organizationalUnit?->name
            ?? $mail->department?->name
            ?? 'the receiving office';

        return redirect()->route('mail.show', $mail)
            ->with('success', "Correspondence {$mail->register_number} filed in {$office}. Its complete assignment and withdrawal history has been preserved.");
    }

    public function reopen(Request $request, MailRecord $mail): RedirectResponse
    {
        $this->authorize('reopen', $mail);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->service->reopen($request->user(), $mail, $validated);

        return redirect()->route('mail.show', $mail)
            ->with('success', "Correspondence {$mail->register_number} reopened and returned to Active Incoming.");
    }
}
