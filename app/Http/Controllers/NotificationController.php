<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications, private AuditLogger $audit) {}

    public function markRead(Request $request, int $notification): RedirectResponse
    {
        $this->notifications->markRead($request->user(), $notification);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($request->user());

        return back();
    }

    public function settings(Request $request): Response
    {
        $preference = NotificationPreference::firstOrCreate(['user_id' => $request->user()->id]);

        return Inertia::render('notifications/settings', [
            'preferences' => $preference->only([
                'in_app_enabled', 'browser_enabled', 'new_assignments', 'assignment_views',
                'deadline_reminders', 'completion_notifications', 'correspondence_updates',
                'annotation_updates', 'office_correspondence',
            ]),
            'permissionDeniedBefore' => $preference->browser_permission_denied_at !== null,
            'activeDeviceCount' => $request->user()->pushSubscriptions()->whereNull('revoked_at')->count(),
            'vapidPublicKey' => (string) config('pwa.vapid.public_key'),
            'pushConfigured' => filled(config('pwa.vapid.public_key')) && filled(config('pwa.vapid.private_key')),
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'in_app_enabled' => ['required', 'boolean'],
            'browser_enabled' => ['required', 'boolean'],
            'new_assignments' => ['required', 'boolean'],
            'assignment_views' => ['required', 'boolean'],
            'deadline_reminders' => ['required', 'boolean'],
            'completion_notifications' => ['required', 'boolean'],
            'correspondence_updates' => ['required', 'boolean'],
            'annotation_updates' => ['required', 'boolean'],
            'office_correspondence' => ['required', 'boolean'],
        ]);
        $preference = NotificationPreference::firstOrCreate(['user_id' => $request->user()->id]);
        $before = $preference->only(array_keys($data));
        $preference->update($data);

        if (! $preference->browser_enabled) {
            $request->user()->pushSubscriptions()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        $this->audit->log('settings', 'Updated notification preferences', $request->user(), 'NotificationPreference', $preference->id, [
            'before' => $before,
            'after' => $data,
        ]);

        return back()->with('success', 'Notification preferences saved.');
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:4000'],
            'public_key' => ['required', 'string', 'max:1000'],
            'auth_token' => ['required', 'string', 'max:1000'],
            'content_encoding' => ['nullable', 'string', 'max:30'],
            'device_label' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $hash = hash('sha256', $data['endpoint']);
        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => $hash],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['public_key'],
                'auth_token' => $data['auth_token'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'device_label' => $data['device_label'] ?? substr((string) $request->userAgent(), 0, 255),
                'expires_at' => $data['expires_at'] ?? null,
                'revoked_at' => null,
                'last_used_at' => now(),
            ],
        );
        NotificationPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['browser_enabled' => true, 'browser_permission_denied_at' => null],
        );
        $this->audit->log('settings', 'Enabled browser notifications on a device', $request->user(), 'PushSubscription', $subscription->id);

        return response()->json(['saved' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['nullable', 'url', 'max:4000']]);
        $query = $request->user()->pushSubscriptions()->whereNull('revoked_at');
        if (filled($data['endpoint'] ?? null)) {
            $query->where('endpoint_hash', hash('sha256', $data['endpoint']));
        }
        $count = $query->update(['revoked_at' => now()]);
        $this->audit->log('settings', 'Disabled browser notifications on a device', $request->user(), 'User', $request->user()->id, ['subscriptions_revoked' => $count]);

        return response()->json(['revoked' => $count]);
    }

    public function permissionDenied(Request $request): JsonResponse
    {
        NotificationPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['browser_enabled' => false, 'browser_permission_denied_at' => now()],
        );

        return response()->json(['recorded' => true]);
    }
}
