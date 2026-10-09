<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailRecord;
use App\Services\Mail\BasicMailDirectory;
use App\Services\Mail\CorrespondenceOfficeDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorrespondenceOfficeController extends Controller
{
    public function directory(Request $request, BasicMailDirectory $directory): JsonResponse
    {
        $this->authorize('viewAny', MailRecord::class);
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:255'], 'purpose' => ['sometimes', 'in:source,destination']]);

        return response()->json(['offices' => $directory->search($request->user(), $data['q'], ($data['purpose'] ?? '') === 'source')]);
    }

    public function __invoke(Request $request, MailRecord $mail, CorrespondenceOfficeDirectory $directory): JsonResponse
    {
        $this->authorize('participate', $mail);
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:255'], 'purpose' => ['sometimes', 'in:source,destination']]);

        return response()->json(['offices' => $request->boolean('linked')
            ? app(BasicMailDirectory::class)->search($request->user(), $data['q'], ($data['purpose'] ?? '') === 'source') : $directory->suggestions($data['q'])]);
    }
}
