<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class BrowserPushService
{
    public function __construct(private AuditLogger $audit) {}

    public function deliver(Notification $notification): void
    {
        $subscriptions = PushSubscription::query()
            ->where('user_id', $notification->user_id)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        foreach ($subscriptions as $subscription) {
            $delivery = NotificationDelivery::firstOrCreate([
                'notification_id' => $notification->id,
                'channel' => 'browser',
                'push_subscription_id' => $subscription->id,
            ], ['status' => 'pending']);

            if (! $delivery->wasRecentlyCreated && in_array($delivery->status, ['delivered', 'sent'], true)) {
                continue;
            }

            try {
                $this->send($notification, $subscription);
                $delivery->update([
                    'status' => 'delivered',
                    'attempted_at' => now(),
                    'delivered_at' => now(),
                    'failure_reason' => null,
                ]);
                $subscription->update(['last_used_at' => now()]);
                $this->audit->log('notification', 'Delivered browser notification', null, 'Notification', $notification->id, [
                    'push_subscription_id' => $subscription->id,
                    'delivery_id' => $delivery->id,
                ]);
            } catch (\Throwable $exception) {
                $delivery->update([
                    'status' => 'failed',
                    'attempted_at' => now(),
                    'failure_reason' => substr($exception->getMessage(), 0, 2000),
                ]);

                if ($this->isExpiredSubscription($exception)) {
                    $subscription->update(['revoked_at' => now()]);
                }

                Log::warning('Browser notification delivery failed', [
                    'notification_id' => $notification->id,
                    'subscription_id' => $subscription->id,
                    'error' => $exception->getMessage(),
                ]);
                $this->audit->log('notification', 'Browser notification delivery failed', null, 'Notification', $notification->id, [
                    'push_subscription_id' => $subscription->id,
                    'delivery_id' => $delivery->id,
                    'reason' => substr($exception->getMessage(), 0, 500),
                ], 'failure');
            }
        }
    }

    private function send(Notification $notification, PushSubscription $subscription): void
    {
        if (! class_exists(WebPush::class)) {
            throw new \RuntimeException('The Web Push delivery library is not installed.');
        }

        $publicKey = (string) config('pwa.vapid.public_key');
        $privateKey = (string) config('pwa.vapid.private_key');
        if ($publicKey === '' || $privateKey === '') {
            throw new \RuntimeException('VAPID keys are not configured.');
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('pwa.vapid.subject', config('app.url')),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
        $webPushSubscription = Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token,
            'contentEncoding' => $subscription->content_encoding,
        ]);

        $safe = $notification->sensitive;
        $payload = json_encode([
            'title' => $safe ? 'New assignment' : $notification->message,
            'body' => $safe
                ? 'You have a new assignment. Open the system to view the details.'
                : ($notification->detail ?: $notification->message),
            'url' => $notification->action_url ?: route('home'),
            'tag' => $notification->event_key ?: "notification-{$notification->id}",
            'notification_id' => $notification->id,
        ], JSON_THROW_ON_ERROR);

        $report = $webPush->sendOneNotification($webPushSubscription, $payload, [
            'TTL' => 3600,
            'urgency' => 'normal',
        ]);

        if (! $report->isSuccess()) {
            throw new \RuntimeException($report->getReason() ?: 'The push service rejected the notification.');
        }
    }

    private function isExpiredSubscription(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, '404') || str_contains($message, '410') || str_contains(strtolower($message), 'expired');
    }
}
