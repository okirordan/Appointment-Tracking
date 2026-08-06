<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorrespondenceUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'correspondence_id', 'correspondence_forward_id', 'task_id', 'type', 'body',
        'status_from', 'status_to', 'recipient_summary', 'performed_by_user_id',
        'performed_by_name_snapshot', 'performed_by_title_snapshot',
        'performed_by_role_snapshot', 'created_at',
    ];

    protected $casts = ['recipient_summary' => 'array', 'created_at' => 'datetime'];

    public function correspondence(): BelongsTo
    {
        return $this->belongsTo(Correspondence::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CorrespondenceAttachment::class);
    }
}
