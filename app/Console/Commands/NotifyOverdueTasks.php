<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Task;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Overdue detection (PRD §22.3). Overdue itself is derived, never stored;
 * this job only produces the daily notifications and escalation alerts.
 */
class NotifyOverdueTasks extends Command
{
    protected $signature = 'ats:notify-overdue';

    protected $description = 'Notify assignees and supervisors of overdue tasks';

    public function handle(NotificationService $notifications): int
    {
        $tasks = Task::active()->whereNotNull('due_date')->whereDate('due_date', '<=', today()->addDays(2))
            ->with(['assignedTo', 'assignedBy', 'currentAssignee', 'currentReviewer', 'owner'])->get();

        $sent = 0;

        foreach ($tasks as $task) {
            $days = $task->daysOverdue();
            $message = $task->overdue
                ? "Task {$task->reference} is {$days} day".($days === 1 ? '' : 's').' overdue'
                : "Task {$task->reference} is due ".($task->due_date->isToday() ? 'today' : 'in '.$task->due_date->diffInDays(today()).' day(s)');

            $recipients = collect([$task->currentAssignee, $task->currentReviewer, $task->owner, $task->assignedTo, $task->assignedBy])
                ->filter()->unique('id');
            foreach ($recipients as $recipient) {
                if ($recipient === null || ! $recipient->active) {
                    continue;
                }

                $alreadyNotifiedToday = Notification::where('user_id', $recipient->id)
                    ->where('related_task_id', $task->id)
                    ->where('type', 'deadline')
                    ->whereDate('created_at', today())
                    ->exists();

                if (! $alreadyNotifiedToday) {
                    $notifications->notify($recipient, 'deadline', $message, null, $task);
                    $sent++;
                }
            }
        }

        $this->info("Overdue notifications sent: {$sent}");

        return self::SUCCESS;
    }
}
