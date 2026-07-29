<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecretaryOfficeAttachment extends Model
{
    protected $fillable = [
        'secretary_user_id',
        'supervisor_user_id',
        'organizational_unit_id',
        'official_job_title',
        'starts_at',
        'ends_at',
        'delegated_actions_permitted',
        'delegated_permissions',
        'active',
        'created_by_user_id',
        'ended_by_user_id',
        'reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'delegated_actions_permitted' => 'boolean',
        'delegated_permissions' => 'array',
        'active' => 'boolean',
    ];

    public function secretary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secretary_user_id')->withTrashed();
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id')->withTrashed();
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id')->withTrashed();
    }

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(OfficeScheduleItem::class)->orderBy('starts_at');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $period) => $period->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
