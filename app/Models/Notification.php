<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'type',
        'category',
        'message',
        'detail',
        'related_task_id',
        'related_mail_record_id',
        'action_url',
        'event_key',
        'sensitive',
        'is_read',
        'read_at',
        'created_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'sensitive' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relatedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'related_task_id');
    }

    public function relatedMailRecord(): BelongsTo
    {
        return $this->belongsTo(MailRecord::class, 'related_mail_record_id');
    }

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
