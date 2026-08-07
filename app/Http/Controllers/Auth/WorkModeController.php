<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkModeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === Role::Sysadmin, 403);

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['administration', 'officer'])],
        ]);

        $request->session()->put('work_mode', $validated['mode']);

        return redirect()->route(
            $validated['mode'] === 'administration' ? 'admin.dashboard' : 'officer.dashboard',
        );
    }
}
