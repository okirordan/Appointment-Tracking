<?php

namespace App\Models;

use App\Enums\CorrespondenceStatus;
use App\Enums\Priority;
use App\Enums\Role;
use Database\Factories\MailRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class MailRecord extends Model
{
    /** @use HasFactory<MailRecordFactory> */
    use HasFactory, SoftDeletes;

    public const SEARCHABLE_TEXT_COLUMNS = [
        'direction',
        'register_number',
        'external_id',
        'subject',
        'details',
        'sender_name',
        'sender_organisation',
        'recipient_name',
        'correspondence_reference',
        'registry_file_number',
        'status',
        'priority',
        'financial_year',
        'dispatch_reference',
    ];

    protected $fillable = [
        'direction', 'register_number', 'external_id', 'sender_name', 'sender_organisation',
        'recipient_name', 'subject', 'details', 'correspondence_reference',
        'letter_date', 'received_date', 'sent_date', 'receipt_method',
        'confidentiality', 'registry_file_number', 'captured_by_user_id',
        'assigned_by_user_id', 'task_id', 'assigned_at',
        'office_supervisor_user_id', 'organizational_unit_id', 'department_id', 'prepared_on_behalf_of_user_id',
        'last_processed_by_user_id', 'status', 'priority', 'financial_year',
        'dispatch_method', 'dispatch_reference', 'dispatched_at',
        'reviewed_by_user_id', 'reviewed_at', 'review_notes',
        'approved_by_user_id', 'approved_at', 'archived_at',
    ];

    protected $casts = [
        'letter_date' => 'date',
        'received_date' => 'date',
        'sent_date' => 'date',
        'assigned_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'archived_at' => 'datetime',
        'status' => CorrespondenceStatus::class,
        'priority' => Priority::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (MailRecord $mail) {
            $mail->status ??= $mail->direction === 'outgoing'
                ? ($mail->sent_date === null ? CorrespondenceStatus::Draft : CorrespondenceStatus::Dispatched)
                : ($mail->task_id === null ? CorrespondenceStatus::Registered : CorrespondenceStatus::Assigned);
            $mail->priority ??= Priority::Medium;
            $mail->last_processed_by_user_id ??= $mail->captured_by_user_id;
            $mail->office_supervisor_user_id ??= User::query()
                ->where('role', Role::Ps->value)
                ->where('active', true)
                ->orderBy('id')
                ->value('id');
            $mail->organizational_unit_id ??= OrganizationalUnit::query()
                ->where('name', 'Office of the Permanent Secretary')
                ->where('active', true)
                ->value('id');

            if ($mail->financial_year === null) {
                $date = $mail->received_date ?? $mail->sent_date ?? $mail->letter_date ?? now();
                $parsed = Carbon::parse($date);
                $start = $parsed->month >= 7 ? $parsed->year : $parsed->year - 1;
                $mail->financial_year = sprintf('%d/%02d', $start, ($start + 1) % 100);
            }
        });
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function officeSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'office_supervisor_user_id')->withTrashed();
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function preparedOnBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_on_behalf_of_user_id')->withTrashed();
    }

    public function lastProcessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_processed_by_user_id')->withTrashed();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id')->withTrashed();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id')->withTrashed();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class)->orderBy('uploaded_at');
    }

    public function isIncoming(): bool
    {
        return $this->direction === 'incoming';
    }

    public function scopeMatchingKeywords(Builder $query, string $term): Builder
    {
        if ($query->getConnection()->getDriverName() === 'mysql') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($term)) === 1) {
                return $query->where(fn (Builder $match) => $match
                    ->where('letter_date', $term)
                    ->orWhere('received_date', $term)
                    ->orWhere('sent_date', $term));
            }

            if (preg_match('/^\d{4}\/\d{2}$/', trim($term)) === 1) {
                return $query->where('financial_year', trim($term));
            }

            if (preg_match('/^[\p{L}\p{N}]+(?:[-\/][\p{L}\p{N}]+)+$/u', trim($term)) === 1) {
                $prefix = str_replace(['%', '_'], ['\\%', '\\_'], trim($term)).'%';

                return $query->where(fn (Builder $match) => $match
                    ->where('register_number', 'like', $prefix)
                    ->orWhere('external_id', 'like', $prefix)
                    ->orWhere('correspondence_reference', 'like', $prefix)
                    ->orWhere('registry_file_number', 'like', $prefix)
                    ->orWhere('dispatch_reference', 'like', $prefix));
            }

            $booleanTerm = self::fullTextSearchTerm($term);
            if ($booleanTerm !== null) {
                return $query->whereFullText(
                    self::SEARCHABLE_TEXT_COLUMNS,
                    $booleanTerm,
                    ['mode' => 'boolean'],
                );
            }

            // Very short terms are not indexed by MySQL full-text search.
            // Keep these bounded to prefix matching on concise identifiers
            // instead of reverting to a 73k-row contains scan.
            $prefix = str_replace(['%', '_'], ['\\%', '\\_'], trim($term)).'%';

            return $query->where(fn (Builder $match) => $match
                ->where('register_number', 'like', $prefix)
                ->orWhere('external_id', 'like', $prefix)
                ->orWhere('correspondence_reference', 'like', $prefix)
                ->orWhere('registry_file_number', 'like', $prefix)
                ->orWhere('dispatch_reference', 'like', $prefix)
                ->orWhere('status', 'like', $prefix)
                ->orWhere('priority', 'like', $prefix));
        }

        return $this->scopeLegacyKeywordMatching($query, $term);
    }

    public function scopeOrderBySearchRelevance(Builder $query, string $term): Builder
    {
        $booleanTerm = self::fullTextSearchTerm($term);
        if (
            $query->getConnection()->getDriverName() !== 'mysql'
            || $booleanTerm === null
            || preg_match('/^\d{4}[-\/]\d{2}(?:-\d{2})?$/', trim($term)) === 1
        ) {
            return $query;
        }

        $columns = implode(', ', array_map(
            fn (string $column) => "`{$column}`",
            self::SEARCHABLE_TEXT_COLUMNS,
        ));

        return $query->orderByRaw(
            "MATCH ({$columns}) AGAINST (? IN BOOLEAN MODE) DESC",
            [$booleanTerm],
        );
    }

    public static function fullTextSearchTerm(string $term): ?string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($term))) ?: [];
        $tokens = array_values(array_unique(array_filter(
            $tokens,
            fn (string $token) => mb_strlen($token) >= 3,
        )));

        if ($tokens === []) {
            return null;
        }

        return implode(' ', array_map(
            fn (string $token) => '+'.$token.'*',
            $tokens,
        ));
    }

    private function scopeLegacyKeywordMatching(Builder $query, string $term): Builder
    {
        $keywords = array_filter(preg_split('/\s+/u', trim($term)) ?: []);

        foreach ($keywords as $keyword) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $keyword).'%';
            $query->where(fn (Builder $match) => $match
                ->where('direction', 'like', $like)
                ->orWhere('register_number', 'like', $like)
                ->orWhere('external_id', 'like', $like)
                ->orWhere('subject', 'like', $like)
                ->orWhere('details', 'like', $like)
                ->orWhere('sender_name', 'like', $like)
                ->orWhere('sender_organisation', 'like', $like)
                ->orWhere('recipient_name', 'like', $like)
                ->orWhere('correspondence_reference', 'like', $like)
                ->orWhere('registry_file_number', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhere('priority', 'like', $like)
                ->orWhere('financial_year', 'like', $like)
                ->orWhere('dispatch_reference', 'like', $like)
                ->orWhere('letter_date', 'like', $like)
                ->orWhere('received_date', 'like', $like)
                ->orWhere('sent_date', 'like', $like)
                ->orWhereHas('task.assignedTo', fn (Builder $user) => $user->where('full_name', 'like', $like))
                ->orWhereHas('task.department', fn (Builder $department) => $department->where('name', 'like', $like)));
        }

        return $query;
    }
}
