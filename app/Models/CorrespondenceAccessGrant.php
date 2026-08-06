<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrespondenceAccessGrant extends Model
{
    protected $fillable = [
        'correspondence_id', 'user_id', 'access_level', 'granted_by_user_id',
        'granted_at', 'revoked_at', 'revoked_by_user_id', 'reason',
    ];

    protected $casts = ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function correspondence(): BelongsTo
    {
        return $this->belongsTo(Correspondence::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
