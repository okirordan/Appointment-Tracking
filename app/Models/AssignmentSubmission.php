<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentSubmission extends Model
{
    protected $fillable = ['task_id', 'workflow_step_id', 'submitted_by_user_id', 'submitted_by_title_snapshot', 'status', 'note', 'submitted_at'];

    protected $casts = ['submitted_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(AssignmentWorkflowStep::class, 'workflow_step_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id')->withTrashed();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AssignmentReview::class, 'submission_id');
    }
}
