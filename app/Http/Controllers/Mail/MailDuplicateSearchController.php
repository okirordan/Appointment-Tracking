<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailRecord;
use App\Services\Mail\MailDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailDuplicateSearchController extends Controller
{
    public function __invoke(Request $request, MailDuplicateService $duplicates): JsonResponse
    {
        $this->authorize('create', MailRecord::class);
        $validated = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:500'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'correspondence_reference' => ['nullable', 'string', 'max:255'],
            'mail_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['duplicates' => $duplicates->search($request->user(), $validated)]);
    }
}
