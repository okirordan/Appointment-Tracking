<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailRecord;
use App\Services\Mail\PsOfficeCrossDepartmentAccess;
use App\Services\Mail\RecipientSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailRecipientSearchController extends Controller
{
    public function __construct(
        private RecipientSearchService $search,
        private PsOfficeCrossDepartmentAccess $crossDepartmentAccess,
    ) {}

    public function __invoke(Request $request, MailRecord $mail): JsonResponse
    {
        $this->authorize('assign', $mail);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json([
            'recipients' => $this->search->search($request->user(), $validated['q']),
        ]);
    }

    public function forOutgoing(Request $request): JsonResponse
    {
        $this->authorize('createOutgoingAssignment', MailRecord::class);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json([
            'recipients' => $this->search->search($request->user(), $validated['q']),
        ]);
    }

    public function forMailParty(Request $request): JsonResponse
    {
        $this->authorize('create', MailRecord::class);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json([
            'recipients' => $this->search->directory($request->user(), $validated['q']),
        ]);
    }

    public function forDepartmentInteraction(Request $request, MailRecord $mail): JsonResponse
    {
        abort_unless(
            $this->crossDepartmentAccess->allows($request->user())
                && $request->user()->can('view', $mail),
            403,
        );
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json([
            'recipients' => $this->search->departmentInteractionDirectory($request->user(), $validated['q']),
        ]);
    }
}
