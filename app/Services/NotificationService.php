<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Store an in-app notification. Failures are logged, never thrown:
     * a notification must not invalidate its task transaction (NOT-007).
     */
    public function notify(User $user, string $type, string $message, ?string $detail = null, ?Task $task = null): void
    {
        try {
            Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'message' => $message,
                'detail' => $detail,
                'related_task_id' => $task?->id,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Notification creation failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
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
}
