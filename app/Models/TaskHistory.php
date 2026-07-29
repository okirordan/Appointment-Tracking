<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable history entry (PRD §14.4): no updated_at, and no update or
 * delete paths exist anywhere in the application.
 */
class TaskHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_id',
        'action_type',
        'note',
        'status',
        'progress_percent',
        'performed_by_user_id',
        'performed_by_name_snapshot',
        'performed_by_title_snapshot',
        'performed_by_role',
        'created_at',
    ];

    protected $casts = [
        'progress_percent' => 'integer',
        'created_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
