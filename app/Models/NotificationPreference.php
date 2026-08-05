<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $attributes = [
        'in_app_enabled' => true,
        'browser_enabled' => false,
        'new_assignments' => true,
        'assignment_views' => true,
        'deadline_reminders' => true,
        'completion_notifications' => true,
        'correspondence_updates' => true,
        'annotation_updates' => true,
        'office_correspondence' => true,
    ];

    protected $fillable = [
        'user_id', 'in_app_enabled', 'browser_enabled', 'new_assignments',
        'assignment_views', 'deadline_reminders', 'completion_notifications',
        'correspondence_updates', 'annotation_updates', 'office_correspondence',
        'browser_permission_denied_at',
    ];

    protected $casts = [
        'in_app_enabled' => 'boolean',
        'browser_enabled' => 'boolean',
        'new_assignments' => 'boolean',
        'assignment_views' => 'boolean',
        'deadline_reminders' => 'boolean',
        'completion_notifications' => 'boolean',
        'correspondence_updates' => 'boolean',
        'annotation_updates' => 'boolean',
        'office_correspondence' => 'boolean',
        'browser_permission_denied_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
