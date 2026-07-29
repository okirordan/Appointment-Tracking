<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentReview extends Model
{
    protected $fillable = ['submission_id', 'workflow_step_id', 'reviewer_user_id', 'reviewer_title_snapshot', 'decision', 'comments', 'revised_due_at', 'reviewed_at'];

    protected $casts = ['revised_due_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id')->withTrashed();
    }
}
