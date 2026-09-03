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
        'routing_status', 'received_at', 'received_by_user_id',
        'removed_by_user_id', 'removed_at', 'removal_reason',
    ];

    protected $casts = [
        'due_date' => 'date', 'active' => 'boolean', 'added_at' => 'datetime', 'received_at' => 'datetime', 'removed_at' => 'datetime',
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

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id')->withTrashed();
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id')->withTrashed();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
