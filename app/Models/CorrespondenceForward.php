<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorrespondenceForward extends Model
{
    protected $fillable = [
        'correspondence_id', 'forwarded_by_user_id', 'on_behalf_of_user_id',
        'from_organizational_unit_id', 'instructions', 'status', 'forwarded_at',
        'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason',
    ];

    protected $casts = ['forwarded_at' => 'datetime', 'withdrawn_at' => 'datetime'];

    public function correspondence(): BelongsTo
    {
        return $this->belongsTo(Correspondence::class);
    }

    public function forwardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by_user_id')->withTrashed();
    }

    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of_user_id')->withTrashed();
    }

    public function fromOrganizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'from_organizational_unit_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CorrespondenceRecipient::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CorrespondenceAttachment::class);
    }
}
