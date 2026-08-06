<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\MailRecord;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        private BrowserPushService $browserPush,
        private EmailNotificationService $email,
        private AuditLogger $audit,
    ) {}

    /**
     * Store an in-app notification. Failures are logged, never thrown:
     * a notification must not invalidate its task transaction (NOT-007).
     */
    public function notify(
        User $user,
        string $type,
        string $message,
        ?string $detail = null,
        ?Task $task = null,
        ?MailRecord $mail = null,
        ?string $eventKey = null,
        ?string $preferenceCategory = null,
        bool $sensitive = false,
    ): ?Notification {
        try {
            if (! $user->active || $user->locked || $user->trashed()) {
                return null;
            }
            if ($user->role === Role::Sysadmin && $this->isMailRelated($task, $mail)) {
                return null;
            }

            $preference = NotificationPreference::firstOrCreate(['user_id' => $user->id]);
            $preferenceCategory ??= $this->preferenceCategory($type);
            if (! $this->categoryEnabled($preference, $preferenceCategory)) {
                return null;
            }

            $attributes = [
                'user_id' => $user->id,
                'type' => $type,
                'category' => $preferenceCategory,
                'message' => $message,
                'detail' => $detail,
                'related_task_id' => $task?->id,
                'related_mail_record_id' => $mail?->id,
                'action_url' => $task !== null
                    ? route('tasks.show', $task)
                    : ($mail !== null ? route('mail.show', $mail) : route('home')),
                'event_key' => $eventKey,
                'sensitive' => $sensitive,
                'created_at' => now(),
            ];
            $notification = $eventKey === null
                ? Notification::create($attributes)
                : Notification::firstOrCreate(['user_id' => $user->id, 'event_key' => $eventKey], $attributes);

            if ($preference->in_app_enabled) {
                NotificationDelivery::firstOrCreate([
                    'notification_id' => $notification->id,
                    'channel' => 'in_app',
                    'push_subscription_id' => null,
                ], [
                    'status' => 'delivered',
                    'attempted_at' => now(),
                    'delivered_at' => now(),
                ]);
            }

            if ($preference->browser_enabled) {
                $this->browserPush->deliver($notification);
            }
            if ($preference->email_enabled) {
                $this->email->deliver($notification, $user);
            }

            $this->audit->log(
                'notification',
                "Created {$type} notification",
                request()?->user(),
                'Notification',
                $notification->id,
                [
                    'recipient_user_id' => $user->id,
                    'category' => $preferenceCategory,
                    'related_task_id' => $task?->id,
                    'related_mail_record_id' => $mail?->id,
                    'in_app_enabled' => $preference->in_app_enabled,
                    'browser_enabled' => $preference->browser_enabled,
                    'email_enabled' => $preference->email_enabled,
                ],
            );

            return $notification;
        } catch (\Throwable $e) {
            Log::error('Notification creation failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function markRead(User $user, int $notificationId): void
    {
        Notification::where('user_id', $user->id)
            ->where('id', $notificationId)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    public function markAllRead(User $user): void
    {
        Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    public function markRelatedTaskRead(User $user, Task $task): void
    {
        Notification::where('user_id', $user->id)
            ->where('related_task_id', $task->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    private function preferenceCategory(string $type): string
    {
        return match ($type) {
            'new_assignment', 'task', 'delegation', 'reassignment' => 'new_assignments',
            'assignment_viewed' => 'assignment_views',
            'deadline', 'overdue' => 'deadline_reminders',
            'completion', 'progress', 'review' => 'completion_notifications',
            'annotation' => 'annotation_updates',
            'correspondence_assigned', 'correspondence_forwarded', 'correspondence_review', 'correspondence_reviewed' => 'correspondence_updates',
            default => 'new_assignments',
        };
    }

    private function categoryEnabled(NotificationPreference $preference, string $category): bool
    {
        return match ($category) {
            'new_assignments' => $preference->new_assignments,
            'assignment_views' => $preference->assignment_views,
            'deadline_reminders' => $preference->deadline_reminders,
            'completion_notifications' => $preference->completion_notifications,
            'correspondence_updates' => $preference->correspondence_updates,
            'annotation_updates' => $preference->annotation_updates,
            'office_correspondence' => $preference->office_correspondence,
            default => true,
        };
    }

    private function isMailRelated(?Task $task, ?MailRecord $mail): bool
    {
        return $mail !== null
            || ($task !== null && (
                $task->mailRecord()->exists()
                || $task->forwardingRecord()->exists()
            ));
    }
}
