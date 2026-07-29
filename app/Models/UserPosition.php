<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPosition extends Model
{
    protected $fillable = ['user_id', 'position_id', 'supervisor_user_id', 'acting_for_user_id', 'is_primary', 'is_acting', 'starts_at', 'ends_at', 'active'];

    protected $casts = ['is_primary' => 'boolean', 'is_acting' => 'boolean', 'active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function actingFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_user_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
