<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('admin/settings', [
            'branding' => [
                'ministry_full_name' => Setting::value('ministry_full_name', config('ats.ministry_full_name')),
                'ministry_short_name' => Setting::value('ministry_short_name', config('ats.ministry_short_name')),
                'system_title' => Setting::value('system_title', config('ats.system_title')),
            ],
            'purgeEnabled' => (bool) config('ats.allow_demo_purge'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ministry_full_name' => ['required', 'string', 'max:255'],
            'ministry_short_name' => ['required', 'string', 'max:50'],
            'system_title' => ['required', 'string', 'max:255'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::put($key, $value);
        }

        $this->audit->log('settings', 'Updated ministry branding', $request->user(), 'Setting', null, $validated);

        return back()->with('success', 'Branding settings saved.');
    }

    /**
     * SET-004/005: disabled by default in every environment; when enabled
     * for a controlled migration it requires recent password confirmation
     * and writes a full audit entry.
     */
    public function purgeDemoData(Request $request): RedirectResponse
    {
        abort_unless((bool) config('ats.allow_demo_purge'), 403);

        $counts = [
            'tasks' => DB::table('tasks')->count(),
            'task_histories' => DB::table('task_histories')->count(),
            'evidence_attachments' => DB::table('evidence_attachments')->count(),
            'notifications' => DB::table('notifications')->count(),
        ];

        DB::transaction(function () {
            DB::table('evidence_attachments')->delete();
            DB::table('notifications')->delete();
            DB::table('task_histories')->delete();
            DB::table('tasks')->delete();
        });

        $this->audit->log('settings', 'Purged demo task data', $request->user(), null, null, $counts);

        return back()->with('success', 'Demo task data purged.');
    }
}
