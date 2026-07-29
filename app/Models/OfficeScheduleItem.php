<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeScheduleItem extends Model
{
    protected $fillable = [
        'secretary_office_attachment_id',
        'type',
        'title',
        'notes',
        'starts_at',
        'ends_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(SecretaryOfficeAttachment::class, 'secretary_office_attachment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
