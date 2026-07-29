<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPositionChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'previous_position_id',
        'new_position_id',
        'previous_role_id',
        'new_role_id',
        'previous_title',
        'new_title',
        'effective_date',
        'changed_at',
        'changed_by_user_id',
        'reason',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'changed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function previousPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'previous_position_id')->withTrashed();
    }

    public function newPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'new_position_id')->withTrashed();
    }

    public function previousRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'previous_role_id');
    }

    public function newRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'new_role_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id')->withTrashed();
    }
}
