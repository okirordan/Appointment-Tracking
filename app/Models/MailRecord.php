<?php

namespace App\Models;

use App\Enums\CorrespondenceLifecycleStatus;
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
        'direction', 'register_number', 'submission_token', 'external_id', 'sender_name', 'sender_organisation',
        'source_type', 'annotation_title_id', 'source_staff_user_id', 'external_source',
        'correspondence_id',
        'recipient_name', 'destination_type', 'recipient_annotation_title_id', 'recipient_staff_user_id',
        'subject', 'details', 'correspondence_reference',
        'letter_date', 'received_date', 'sent_date', 'receipt_method',
        'confidentiality', 'registry_file_number', 'captured_by_user_id',
        'assigned_by_user_id', 'task_id', 'assigned_at',
        'source_mail_record_id', 'routing_task_id',
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
            // Only correspondence without departmental ownership defaults to
            // the PS Office. Stamping it onto department mail would make the
            // two indistinguishable for office-level access control.
            if ($mail->department_id === null) {
                $mail->organizational_unit_id ??= OrganizationalUnit::query()
                    ->where('name', 'Office of the Permanent Secretary')
                    ->where('active', true)
                    ->value('id');
            }

            if ($mail->financial_year === null) {
                $date = $mail->received_date ?? $mail->sent_date ?? $mail->letter_date ?? now();
                $parsed = Carbon::parse($date);
                $start = $parsed->month >= 7 ? $parsed->year : $parsed->year - 1;
                $mail->financial_year = sprintf('%d/%02d', $start, ($start + 1) % 100);
            }
        });

        static::created(function (MailRecord $mail) {
            if ($mail->correspondence_id !== null) {
                return;
            }

            $lifecycle = match (true) {
                in_array($mail->status, [CorrespondenceStatus::Completed, CorrespondenceStatus::Archived, CorrespondenceStatus::Delivered], true) => CorrespondenceLifecycleStatus::Closed,
                $mail->status === CorrespondenceStatus::AwaitingReview => CorrespondenceLifecycleStatus::UnderReview,
                $mail->status === CorrespondenceStatus::Assigned || $mail->task_id !== null => CorrespondenceLifecycleStatus::ActionRequired,
                $mail->direction === 'outgoing' || $mail->status === CorrespondenceStatus::Forwarded => CorrespondenceLifecycleStatus::Forwarded,
                default => CorrespondenceLifecycleStatus::Incoming,
            };

            $correspondence = Correspondence::create([
                'canonical_reference' => $mail->register_number,
                'origin_direction' => $mail->direction,
                'originating_mail_record_id' => $mail->id,
                'office_supervisor_user_id' => $mail->office_supervisor_user_id,
                'organizational_unit_id' => $mail->organizational_unit_id,
                'department_id' => $mail->department_id,
                'confidentiality' => $mail->confidentiality,
                'current_status' => $lifecycle,
                'last_activity_at' => $mail->updated_at ?? now(),
                'closed_at' => $lifecycle === CorrespondenceLifecycleStatus::Closed ? now() : null,
            ]);
            $mail->updateQuietly(['correspondence_id' => $correspondence->id]);
        });
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function annotationTitle(): BelongsTo
    {
        return $this->belongsTo(AnnotationTitle::class);
    }

    public function recipientAnnotationTitle(): BelongsTo
    {
        return $this->belongsTo(AnnotationTitle::class, 'recipient_annotation_title_id');
    }

    public function sourceStaffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_staff_user_id')->withTrashed();
    }

    public function recipientStaffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_staff_user_id')->withTrashed();
    }

    public function correspondence(): BelongsTo
    {
        return $this->belongsTo(Correspondence::class);
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

    public function routingTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'routing_task_id');
    }

    public function sourceMailRecord(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_mail_record_id');
    }

    public function forwardedRecords(): HasMany
    {
        return $this->hasMany(self::class, 'source_mail_record_id')->orderByDesc('sent_date');
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
        $term = preg_replace('/\s+/u', ' ', trim($term)) ?? trim($term);

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $term, $parts) === 1) {
            $date = sprintf('%04d-%02d-%02d', (int) $parts[3], (int) $parts[2], (int) $parts[1]);

            return $query->where(fn (Builder $match) => $match
                ->whereDate('letter_date', $date)
                ->orWhereDate('received_date', $date)
                ->orWhereDate('sent_date', $date));
        }

        if ($query->getConnection()->getDriverName() === 'mysql') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($term)) === 1) {
                return $query->where(fn (Builder $match) => $match
                    ->whereDate('letter_date', $term)
                    ->orWhereDate('received_date', $term)
                    ->orWhereDate('sent_date', $term));
            }

            if (preg_match('/^\d{4}\/\d{2}$/', trim($term)) === 1) {
                return $query->where('financial_year', trim($term));
            }

            if (preg_match('/^[\p{L}\p{N}]+(?:[-\/][\p{L}\p{N}]+)+$/u', trim($term)) === 1
                && preg_match('/\d/u', trim($term)) === 1) {
                $prefix = str_replace(['%', '_'], ['\\%', '\\_'], trim($term)).'%';

                return $query->where(fn (Builder $match) => $match
                    ->where('register_number', 'like', $prefix)
                    ->orWhere('external_id', 'like', $prefix)
                    ->orWhere('correspondence_reference', 'like', $prefix)
                    ->orWhere('registry_file_number', 'like', $prefix)
                    ->orWhere('dispatch_reference', 'like', $prefix));
            }

            $booleanTerm = self::fullTextSearchTerm($term);
            $tokens = self::searchTokens($term);
            $flexibleLike = '%'.implode('%', array_map(
                fn (string $token) => str_replace(['%', '_'], ['\\%', '\\_'], $token),
                $tokens,
            )).'%';

            return $query->where(function (Builder $match) use ($booleanTerm, $flexibleLike) {
                if ($booleanTerm !== null) {
                    $match->whereFullText(
                        self::SEARCHABLE_TEXT_COLUMNS,
                        $booleanTerm,
                        ['mode' => 'boolean'],
                    );
                } else {
                    $match->where('register_number', 'like', $flexibleLike);
                }

                // This deliberately covers the human-entered From/To values
                // in addition to full-text search. It fixes punctuation,
                // short-token and extra-space variations that MySQL's word
                // parser cannot represent reliably.
                $match->orWhere('sender_name', 'like', $flexibleLike)
                    ->orWhere('sender_organisation', 'like', $flexibleLike)
                    ->orWhere('recipient_name', 'like', $flexibleLike)
                    ->orWhere('subject', 'like', $flexibleLike)
                    ->orWhere('details', 'like', $flexibleLike)
                    ->orWhere('register_number', 'like', $flexibleLike)
                    ->orWhere('correspondence_reference', 'like', $flexibleLike)
                    ->orWhere('registry_file_number', 'like', $flexibleLike)
                    ->orWhere('dispatch_reference', 'like', $flexibleLike)
                    ->orWhereHas('department', fn (Builder $department) => $department
                        ->where('name', 'like', $flexibleLike)->orWhere('code', 'like', $flexibleLike))
                    ->orWhereHas('organizationalUnit', fn (Builder $office) => $office
                        ->where('name', 'like', $flexibleLike)->orWhere('code', 'like', $flexibleLike))
                    ->orWhereHas('task', fn (Builder $task) => $task
                        ->where('assigned_to_name_snapshot', 'like', $flexibleLike)
                        ->orWhereHas('assignedTo', fn (Builder $user) => $user
                            ->where('full_name', 'like', $flexibleLike)->orWhere('title', 'like', $flexibleLike))
                        ->orWhereHas('histories', fn (Builder $history) => $history->where('note', 'like', $flexibleLike)))
                    ->orWhereHas('routingTask', fn (Builder $task) => $task
                        ->where('assigned_to_name_snapshot', 'like', $flexibleLike)
                        ->orWhereHas('histories', fn (Builder $history) => $history->where('note', 'like', $flexibleLike)));
            });
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

        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($term)) ?? trim($term));

        return $query
            ->orderByRaw(
                'CASE
                    WHEN LOWER(TRIM(sender_name)) = ? OR LOWER(TRIM(recipient_name)) = ? OR LOWER(TRIM(subject)) = ? THEN 0
                    WHEN LOWER(sender_name) LIKE ? OR LOWER(recipient_name) LIKE ? OR LOWER(subject) LIKE ? THEN 1
                    ELSE 2
                END',
                [$normalized, $normalized, $normalized, $normalized.'%', $normalized.'%', $normalized.'%'],
            )
            ->orderByRaw(
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

    /** @return list<string> */
    private static function searchTokens(string $term): array
    {
        $tokens = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($term))) ?: [],
            fn (string $token) => $token !== '',
        )));

        return $tokens === [] ? [mb_strtolower(trim($term))] : $tokens;
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
                ->orWhereHas('task.assignedTo', fn (Builder $user) => $user->orWhere('title', 'like', $like))
                ->orWhereHas('task.histories', fn (Builder $history) => $history->where('note', 'like', $like))
                ->orWhereHas('routingTask.histories', fn (Builder $history) => $history->where('note', 'like', $like))
                ->orWhereHas('task.department', fn (Builder $department) => $department->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->orWhereHas('department', fn (Builder $department) => $department->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->orWhereHas('organizationalUnit', fn (Builder $office) => $office->where('name', 'like', $like)->orWhere('code', 'like', $like)));
        }

        return $query;
    }
}
