<?php

namespace App\Pulse;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Laravel\Pulse\Pulse;

/** Monitor the existing overdue-assignment schedule without job payloads. */
class ScheduledAssignmentTasks
{
    public array $listen = [ScheduledTaskFinished::class, ScheduledTaskFailed::class];

    public function __construct(private Pulse $pulse) {}

    public function record(ScheduledTaskFinished|ScheduledTaskFailed $event): void
    {
        if ($event->task->description !== 'ats:notify-overdue') {
            return;
        }

        if ($event instanceof ScheduledTaskFinished) {
            $this->pulse->record('assignment_scheduled', 'ats:notify-overdue', (int) round($event->runtime * 1000))
                ->count()->avg()->max();
        } else {
            $this->pulse->record('assignment_scheduled_failure', 'ats:notify-overdue')->count();
        }
    }
}
