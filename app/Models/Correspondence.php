<?php

namespace App\Models;

use App\Enums\CorrespondenceLifecycleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Correspondence extends Model
{
    protected $fillable = [
        'canonical_reference', 'origin_direction', 'originating_mail_record_id',
        'office_supervisor_user_id', 'organizational_unit_id', 'department_id',
        'confidentiality', 'current_status', 'last_activity_at', 'closed_at',
        'withdrawn_at', 'lock_version',
        'filed_at', 'filed_by_user_id', 'filed_organizational_unit_id',
        'filed_department_id', 'filing_category', 'filing_note',
    ];

    protected $casts = [
        'current_status' => CorrespondenceLifecycleStatus::class,
        'last_activity_at' => 'datetime',
        'closed_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'filed_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function mailRecords(): HasMany
    {
        return $this->hasMany(MailRecord::class);
    }

    public function originatingMailRecord(): BelongsTo
    {
        return $this->belongsTo(MailRecord::class, 'originating_mail_record_id');
    }

    public function forwards(): HasMany
    {
        return $this->hasMany(CorrespondenceForward::class)->orderBy('forwarded_at');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CorrespondenceRecipient::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(CorrespondenceUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CorrespondenceAttachment::class)->orderBy('uploaded_at');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(CorrespondenceAccessGrant::class);
    }

    public function officeSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'office_supervisor_user_id')->withTrashed();
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by_user_id')->withTrashed();
    }

    public function filedOrganizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'filed_organizational_unit_id');
    }

    public function filedDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'filed_department_id');
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visible) use ($user) {
            $visible->whereHas('recipients', fn (Builder $recipient) => $recipient
                ->where('user_id', $user->id)->where('active', true))
                ->orWhereHas('accessGrants', fn (Builder $grant) => $grant
                    ->where('user_id', $user->id)->whereNull('revoked_at'));
        });
    }
}
