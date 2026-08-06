<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'performed_by_office_snapshot',
        'performed_by_role',
        'created_at',
    ];

    protected $casts = [
        'progress_percent' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $history): void {
            if ($history->performed_by_user_id === null) {
                return;
            }

            $author = User::withTrashed()->find($history->performed_by_user_id);
            $history->performed_by_title_snapshot ??= $author?->officialTitle();
            $history->performed_by_office_snapshot ??= $author?->officialOfficeName();
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id')->withTrashed();
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceAttachment::class, 'history_id')->orderBy('uploaded_at')->orderBy('id');
    }
}
