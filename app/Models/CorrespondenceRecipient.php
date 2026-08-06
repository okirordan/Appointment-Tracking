<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrespondenceRecipient extends Model
{
    protected $fillable = [
        'correspondence_id', 'correspondence_forward_id', 'recipient_type', 'purpose',
        'target_type', 'user_id', 'organizational_unit_id', 'department_id', 'task_id',
        'external_name', 'external_organisation', 'recipient_name_snapshot',
        'recipient_title_snapshot', 'due_date', 'active', 'added_by_user_id', 'added_at',
        'removed_by_user_id', 'removed_at', 'removal_reason',
    ];

    protected $casts = [
        'due_date' => 'date', 'active' => 'boolean', 'added_at' => 'datetime', 'removed_at' => 'datetime',
    ];

    public function correspondence(): BelongsTo
    {
        return $this->belongsTo(Correspondence::class);
    }

    public function forward(): BelongsTo
    {
        return $this->belongsTo(CorrespondenceForward::class, 'correspondence_forward_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
