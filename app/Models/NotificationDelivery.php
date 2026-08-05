<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_id', 'push_subscription_id', 'channel', 'status',
        'attempted_at', 'delivered_at', 'failure_reason',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function pushSubscription(): BelongsTo
    {
        return $this->belongsTo(PushSubscription::class);
    }
}
