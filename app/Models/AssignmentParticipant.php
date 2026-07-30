<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentParticipant extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'participant_type',
        'active',
        'assigned_at',
        'unassigned_at',
        'added_by_user_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
