<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\EmailNotificationService;
use App\Services\Mail\MailFeatureSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private EmailNotificationService $email,
        private MailFeatureSettings $mailFeatures,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/settings', [
            'branding' => [
                'ministry_full_name' => Setting::value('ministry_full_name', config('ats.ministry_full_name')),
                'ministry_short_name' => Setting::value('ministry_short_name', config('ats.ministry_short_name')),
                'system_title' => Setting::value('system_title', config('ats.system_title')),
            ],
            'purgeEnabled' => (bool) config('ats.allow_demo_purge'),
            'emailConfiguration' => $this->email->publicConfiguration(),
            'mailFeatures' => collect($this->mailFeatures->definitions())
                ->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $label,
                    'enabled' => $this->mailFeatures->enabled($key),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function updateMailFeatures(Request $request): RedirectResponse
    {
        $keys = array_keys($this->mailFeatures->definitions());
        $validated = $request->validate([
            'features' => ['required', 'array'],
            'features.*' => ['required', 'boolean'],
        ]);

        $saved = [];
        foreach ($keys as $key) {
            $enabled = (bool) ($validated['features'][$key] ?? false);
            $this->mailFeatures->set($key, $enabled);
            $saved[$key] = $enabled;
        }

        $this->audit->log('settings', 'Updated correspondence form features', $request->user(), 'Setting', null, $saved);

        return back()->with('success', 'Correspondence form settings saved.');
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:enabled,true', 'nullable', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'clear_password' => ['nullable', 'boolean'],
            'from_address' => ['required_if:enabled,true', 'nullable', 'email:rfc', 'max:255'],
            'from_name' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
        ]);

        Setting::put('email_notifications_enabled', $validated['enabled'] ? '1' : '0');
        Setting::put('mail_smtp_host', $validated['host'] ?? null);
        Setting::put('mail_smtp_port', isset($validated['port']) ? (string) $validated['port'] : null);
        Setting::put('mail_smtp_encryption', $validated['encryption']);
        Setting::put('mail_smtp_username', $validated['username'] ?? null);
        Setting::put('mail_from_address', $validated['from_address'] ?? null);
        Setting::put('mail_from_name', $validated['from_name'] ?? null);
        if ($request->boolean('clear_password')) {
            Setting::put('mail_smtp_password', null);
        } elseif (filled($validated['password'] ?? null)) {
            Setting::put('mail_smtp_password', Crypt::encryptString($validated['password']));
        }

        $this->audit->log('settings', 'Updated email notification configuration', $request->user(), 'Setting', null, [
            'enabled' => $validated['enabled'],
            'host' => $validated['host'] ?? null,
            'port' => $validated['port'] ?? null,
            'encryption' => $validated['encryption'],
            'username_configured' => filled($validated['username'] ?? null),
            'password_changed' => filled($validated['password'] ?? null),
            'password_cleared' => $request->boolean('clear_password'),
            'from_address' => $validated['from_address'] ?? null,
            'from_name' => $validated['from_name'] ?? null,
        ]);

        return back()->with('success', 'Email notification configuration saved.');
    }

    public function testEmail(Request $request): RedirectResponse
    {
        $data = $request->validate(['recipient' => ['required', 'email:rfc', 'max:255']]);
        if (! $this->email->isConfigured()) {
            throw ValidationException::withMessages(['recipient' => 'Enable and save a complete email configuration before sending a test.']);
        }

        try {
            $this->email->sendTest($data['recipient']);
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['recipient' => 'The test email could not be sent. Check the SMTP server, credentials, encryption and network access.']);
        }

        $this->audit->log('settings', 'Sent email configuration test', $request->user(), 'Setting', null, [
            'recipient' => $data['recipient'],
        ]);

        return back()->with('success', "Test email sent to {$data['recipient']}.");
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
