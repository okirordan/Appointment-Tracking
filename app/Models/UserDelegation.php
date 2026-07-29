<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDelegation extends Model
{
    protected $fillable = ['delegator_user_id', 'delegate_user_id', 'organizational_unit_id', 'starts_at', 'ends_at', 'reason', 'active', 'created_by_user_id'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'active' => 'boolean'];

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id')->withTrashed();
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id')->withTrashed();
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('active', true)->where('starts_at', '<=', now())->where('ends_at', '>=', now());
    }
}
