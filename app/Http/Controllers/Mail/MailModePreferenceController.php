<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Services\ImpersonationService;
use App\Services\Mail\MailModePreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MailModePreferenceController extends Controller
{
    public function __construct(private MailModePreferences $preferences) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizePreferences($request);

        return response()->json(['preferences' => $this->preferences->state($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizePreferences($request);
        $data = $request->validate([
            'default_mode' => ['sometimes', 'required', Rule::in(['basic', 'full', 'ask'])],
            'tips_enabled' => ['sometimes', 'required', 'boolean'],
        ]);
        $this->preferences->save($request, $data);

        return response()->json(['preferences' => $this->preferences->state($request)]);
    }

    public function choose(Request $request): JsonResponse
    {
        $this->authorizePreferences($request);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['basic', 'full', 'later'])],
            'save_default' => ['sometimes', 'boolean'],
        ]);
        $later = $data['mode'] === 'later';
        $this->preferences->choose($request, $later ? 'full' : $data['mode'], ! $later && ($data['save_default'] ?? false));

        return response()->json(['preferences' => $this->preferences->state($request)]);
    }

    public function claimTip(Request $request): JsonResponse
    {
        $this->authorizePreferences($request);

        return response()->json(['show' => $this->preferences->claimTip($request)]);
    }

    private function authorizePreferences(Request $request): void
    {
        abort_unless($this->preferences->available($request->user()), 403);
        abort_if($request->session()->has(ImpersonationService::KEY), 403, 'Return to your own account before changing personal preferences.');
    }
}
