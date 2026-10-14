<?php

namespace App\Services;

use App\Jobs\ReindexMailSearch;
use App\Models\MailRecord;
use Illuminate\Database\Eloquent\Builder;

class MailSearchReindexDispatcher
{
    public function forDepartment(int $id): void
    {
        $this->dispatch(MailRecord::query()->where(fn (Builder $mail) => $mail
            ->where('department_id', $id)
            ->orWhere('source_department_id', $id)
            ->orWhere('recipient_department_id', $id)
            ->orWhereHas('task', fn (Builder $task) => $task->where('department_id', $id))
            ->orWhereHas('routingTask', fn (Builder $task) => $task->where('department_id', $id))));
    }

    public function forOrganizationalUnit(int $id): void
    {
        $this->dispatch(MailRecord::query()->where('organizational_unit_id', $id));
    }

    public function forUser(int $id): void
    {
        $this->dispatch(MailRecord::query()->where(fn (Builder $mail) => $mail
            ->where('source_staff_user_id', $id)
            ->orWhere('recipient_staff_user_id', $id)
            ->orWhereHas('task', fn (Builder $task) => $task->where('assigned_to_user_id', $id))
            ->orWhereHas('routingTask', fn (Builder $task) => $task->where('assigned_to_user_id', $id))));
    }

    public function forAnnotationTitle(int $id): void
    {
        $this->dispatch(MailRecord::query()->where(fn (Builder $mail) => $mail
            ->where('annotation_title_id', $id)
            ->orWhere('recipient_annotation_title_id', $id)));
    }

    public function forTask(int $id): void
    {
        $this->dispatch(MailRecord::query()->where(fn (Builder $mail) => $mail
            ->where('task_id', $id)->orWhere('routing_task_id', $id)));
    }

    /** @param Builder<MailRecord> $query */
    private function dispatch(Builder $query): void
    {
        $query->select('mail_records.id')->chunkById(500, function ($mails) {
            ReindexMailSearch::dispatch($mails->modelKeys())->afterCommit();
        }, 'mail_records.id', 'id');
    }
}
