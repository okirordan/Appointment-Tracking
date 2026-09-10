<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorrespondenceUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'correspondence_id', 'correspondence_forward_id', 'task_id', 'type', 'entry_method', 'body',
        'from_organizational_unit_id', 'to_organizational_unit_id',
        'represented_organizational_unit_id', 'responsible_user_id',
        'status_from', 'status_to', 'recipient_summary', 'performed_by_user_id',
        'performed_by_name_snapshot', 'performed_by_title_snapshot',
        'performed_by_office_snapshot',
        'performed_by_role_snapshot', 'occurred_at', 'recorded_at', 'created_at',
    ];

    protected $casts = [
        'recipient_summary' => 'array',
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $update): void {
            $update->entry_method ??= 'normal';
            $update->recorded_at ??= $update->created_at ?? now();
            $update->occurred_at ??= $update->created_at ?? now();

            if ($update->performed_by_user_id === null) {
                return;
            }

            $author = User::withTrashed()->find($update->performed_by_user_id);
            $update->performed_by_title_snapshot ??= $author?->officialTitle();
            $update->performed_by_office_snapshot ??= $author?->officialOfficeName();
        });
    }

    public function correspondence(): BelongsTo
    {
        return $this->belongsTo(Correspondence::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id')->withTrashed();
    }

    public function forward(): BelongsTo
    {
        return $this->belongsTo(CorrespondenceForward::class, 'correspondence_forward_id');
    }

    public function fromOrganizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'from_organizational_unit_id');
    }

    public function toOrganizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'to_organizational_unit_id');
    }

    public function representedOrganizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'represented_organizational_unit_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CorrespondenceAttachment::class);
    }
}
