<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ChangePasswordController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        return Inertia::render('auth/change-password', [
            'forced' => $request->user()->force_password_change,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            // PRD §12.22 policy: 8+ chars, upper, lower, number, symbol.
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols(), 'different:current_password'],
        ]);

        $user->forceFill([
            'password' => $validated['password'],
            'force_password_change' => false,
            'password_changed_at' => now(),
        ])->save();

        $request->session()->regenerate();

        // PWD-005: audited without recording the password.
        $this->audit->log('security', 'Changed own password', $user, 'User', $user->id);

        return redirect()->route('home')->with('success', 'Password updated.');
    }
}
