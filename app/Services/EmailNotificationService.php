<?php

namespace App\Services;

use App\Mail\SystemNotificationMail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    /** @return array<string, mixed> */
    public function publicConfiguration(): array
    {
        return [
            'enabled' => $this->enabled(),
            'host' => Setting::value('mail_smtp_host', (string) config('mail.mailers.smtp.host')),
            'port' => (int) Setting::value('mail_smtp_port', (string) config('mail.mailers.smtp.port', 587)),
            'encryption' => Setting::value('mail_smtp_encryption', (string) config('mail.mailers.smtp.encryption', 'tls')) ?: 'none',
            'username' => Setting::value('mail_smtp_username', (string) config('mail.mailers.smtp.username')),
            'from_address' => Setting::value('mail_from_address', (string) config('mail.from.address')),
            'from_name' => Setting::value('mail_from_name', (string) config('mail.from.name')),
            'password_configured' => filled(Setting::value('mail_smtp_password')) || filled(config('mail.mailers.smtp.password')),
        ];
    }

    public function enabled(): bool
    {
        return Setting::value('email_notifications_enabled', config('mail.default') === 'smtp' ? '1' : '0') === '1';
    }

    public function isConfigured(): bool
    {
        $configuration = $this->publicConfiguration();

        return $configuration['enabled']
            && filled($configuration['host'])
            && (int) $configuration['port'] > 0
            && filter_var($configuration['from_address'], FILTER_VALIDATE_EMAIL) !== false;
    }

    public function deliver(Notification $notification, User $user): void
    {
        if (! $this->isConfigured() || blank($user->email)) {
            return;
        }

        $delivery = NotificationDelivery::updateOrCreate([
            'notification_id' => $notification->id,
            'channel' => 'email',
            'push_subscription_id' => null,
        ], [
            'status' => 'pending',
            'attempted_at' => now(),
            'delivered_at' => null,
            'failure_reason' => null,
        ]);

        try {
            $this->applyRuntimeConfiguration();
            Mail::to($user->email)->send(new SystemNotificationMail(
                $notification->sensitive ? 'You have a new secure ATS notification' : $notification->message,
                $notification->sensitive ? 'Sign in to ATS to view the protected details.' : $notification->detail,
                $notification->action_url ?: route('home'),
            ));
            $delivery->update(['status' => 'delivered', 'delivered_at' => now()]);
        } catch (\Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'failure_reason' => str($exception->getMessage())->limit(2000)->toString(),
            ]);
            Log::error('Email notification delivery failed', [
                'notification_id' => $notification->id,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function sendTest(string $recipient): void
    {
        $this->applyRuntimeConfiguration();
        Mail::to($recipient)->send(new SystemNotificationMail(
            'ATS email configuration test',
            'Email delivery is configured correctly. Assignment, unassignment and correspondence notifications can now be delivered by email.',
            route('home'),
            'Open ATS',
        ));
    }

    private function applyRuntimeConfiguration(): void
    {
        $configuration = $this->publicConfiguration();
        $password = config('mail.mailers.smtp.password');
        if ($encrypted = Setting::value('mail_smtp_password')) {
            try {
                $password = Crypt::decryptString($encrypted);
            } catch (\Throwable $exception) {
                Log::warning('Stored SMTP password could not be decrypted.', ['error' => $exception->getMessage()]);
                $password = null;
            }
        }
        $encryption = $configuration['encryption'] === 'none' ? null : $configuration['encryption'];

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $configuration['host'],
            'mail.mailers.smtp.port' => (int) $configuration['port'],
            'mail.mailers.smtp.encryption' => $encryption,
            'mail.mailers.smtp.username' => $configuration['username'] ?: null,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => $configuration['from_address'],
            'mail.from.name' => $configuration['from_name'],
        ]);
        $manager = app('mail.manager');
        if (method_exists($manager, 'forgetMailers')) {
            $manager->forgetMailers();
        }
    }
}
