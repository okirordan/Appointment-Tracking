<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentWorkflowStep extends Model
{
    protected $fillable = ['task_id', 'sender_user_id', 'recipient_user_id', 'position_id', 'parent_step_id', 'sequence', 'status', 'instructions', 'assigned_at', 'due_at', 'submitted_at', 'reviewed_at', 'review_decision', 'reviewer_comments', 'is_skipped', 'is_current', 'is_direct'];

    protected $casts = ['sequence' => 'integer', 'assigned_at' => 'datetime', 'due_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'is_skipped' => 'boolean', 'is_current' => 'boolean', 'is_direct' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $step): void {
            $step->sender_name_snapshot ??= $step->sender?->full_name;
            $step->recipient_name_snapshot ??= $step->recipient?->full_name;
            $step->sender_office_snapshot ??= $step->sender?->officialOfficeName();
            $step->recipient_office_snapshot ??= $step->recipient?->officialOfficeName();
            $officer = $step->recipient;
            $position = $officer?->currentPositionAssignment?->position;
            $step->recipient_title_snapshot ??= $position?->title ?? $officer?->title;
            $step->recipient_role_snapshot ??= $officer?->roleLabel();
            $step->recipient_department_snapshot ??= $position?->organizationalUnit?->department?->name ?? $officer?->department?->name;
            $step->recipient_division_snapshot ??= $position?->organizationalUnit?->division?->name ?? $officer?->division?->name;
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id')->withTrashed();
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id')->withTrashed();
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function parentStep(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_step_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class, 'workflow_step_id');
    }
}
