<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskUnassignment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_id',
        'user_id',
        'assignment_workflow_step_id',
        'originally_assigned_by_user_id',
        'unassigned_by_user_id',
        'task_reference_snapshot',
        'task_title_snapshot',
        'assigned_user_name_snapshot',
        'unassigned_by_name_snapshot',
        'original_assignment_at',
        'unassigned_at',
        'reason',
        'comments',
        'status_before',
        'status_after',
        'created_at',
    ];

    protected $casts = [
        'original_assignment_at' => 'datetime',
        'unassigned_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function unassignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unassigned_by_user_id')->withTrashed();
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(AssignmentWorkflowStep::class, 'assignment_workflow_step_id');
    }
}
