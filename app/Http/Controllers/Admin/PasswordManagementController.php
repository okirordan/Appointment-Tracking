<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TemporaryPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PasswordManagementController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * PWD-002/004/008: reset the account to a unique temporary
     * password, unlock it, clear failed attempts, and force a change at
     * next login. Only the fact of the reset is audited — never the
     * password itself.
     */
    public function reset(Request $request, User $user): RedirectResponse
    {
        $temporaryPassword = TemporaryPassword::generate();

        $user->forceFill([
            'password' => $temporaryPassword,
            'force_password_change' => true,
            'locked' => false,
            'failed_login_count' => 0,
            'password_reset_at' => now(),
            'password_reset_by' => $request->user()->id,
        ])->save();

        $this->audit->log('security', "Reset password for {$user->username}", $request->user(), 'User', $user->id);

        // The temporary password is returned as a one-time credential the
        // admin copies and closes manually (PWD-006) — never in a toast that
        // auto-dismisses before it can be read.
        return back()
            ->with('success', "Password reset for {$user->full_name}.")
            ->with('temp_credential', [
                'name' => $user->full_name,
                'username' => $user->username,
                'password' => $temporaryPassword,
                'context' => 'reset',
            ]);
    }

    public function toggleLock(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot lock your own account.');
        }

        $user->forceFill([
            'locked' => ! $user->locked,
            'failed_login_count' => $user->locked ? 0 : $user->failed_login_count,
        ])->save();

        $action = $user->locked ? 'Locked' : 'Unlocked';
        $this->audit->log('security', "{$action} account {$user->username}", $request->user(), 'User', $user->id);

        return back()->with('success', "{$action} {$user->full_name}'s account.");
    }

    public function forceChange(Request $request, User $user): RedirectResponse
    {
        $user->forceFill(['force_password_change' => true])->save();

        $this->audit->log('security', "Required password change for {$user->username}", $request->user(), 'User', $user->id);

        return back()->with('success', "{$user->full_name} must change their password at next sign-in.");
    }
}
