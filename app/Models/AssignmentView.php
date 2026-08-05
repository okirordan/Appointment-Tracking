<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentView extends Model
{
    protected $fillable = [
        'task_id', 'user_id', 'status_before', 'first_viewed_at', 'latest_viewed_at',
        'view_count', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'first_viewed_at' => 'datetime',
        'latest_viewed_at' => 'datetime',
        'view_count' => 'integer',
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
