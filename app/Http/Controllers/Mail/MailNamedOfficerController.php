<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailNamedOfficer;
use App\Models\MailRecord;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailNamedOfficerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('create', MailRecord::class);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:255']]);

        return response()->json([
            'officers' => MailNamedOfficer::query()
                ->where('normalized_name', 'like', '%'.addcslashes(MailNamedOfficer::normalize($validated['q']), '%_\\').'%')
                ->orderBy('full_name')
                ->limit(12)
                ->get(['id', 'full_name']),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorize('create', MailRecord::class);
        $validated = $request->validate(['full_name' => ['required', 'string', 'min:2', 'max:255', 'regex:/\p{L}/u']]);
        $name = MailNamedOfficer::displayName($validated['full_name']);
        $officer = MailNamedOfficer::query()->firstOrCreate(
            ['normalized_name' => MailNamedOfficer::normalize($name)],
            ['full_name' => $name, 'created_by_user_id' => $request->user()->id],
        );
        if ($officer->wasRecentlyCreated) {
            $audit->log('mail', "Saved officer name {$officer->full_name}", $request->user(), 'MailNamedOfficer', $officer->id);
        }

        return response()->json([
            'officer' => $officer->only(['id', 'full_name']),
            'message' => $officer->wasRecentlyCreated ? 'Officer name saved and selected.' : 'Existing officer name selected.',
        ], $officer->wasRecentlyCreated ? 201 : 200);
    }
}
