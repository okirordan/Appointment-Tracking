<?php

namespace App\Models;

use App\Enums\AssignmentLevel;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference',
        'title',
        'description',
        'assignment_level',
        'assignment_target_type',
        'assigned_by_user_id',
        'assigned_by_role_snapshot',
        'assigned_by_department_id',
        'creator_user_id',
        'owner_user_id',
        'assigned_to_user_id',
        'current_assignee_user_id',
        'responsible_user_id',
        'current_reviewer_user_id',
        'final_approver_user_id',
        'assignee_registry_id',
        'assigned_to_name_snapshot',
        'department_id',
        'assigned_to_organizational_unit_id',
        'assigned_to_department_id',
        'division_id',
        'workstream_id',
        'external_id',
        'priority',
        'due_date',
        'original_due_date',
        'workflow_status',
        'execution_status',
        'review_status',
        'approval_status',
        'progress_percent',
        'initial_instruction',
        'first_viewed_at',
        'first_viewed_by_user_id',
        'last_viewed_at',
        'completed_at',
        'archived_at',
    ];

    protected $casts = [
        'assignment_level' => AssignmentLevel::class,
        'priority' => Priority::class,
        'workflow_status' => TaskStatus::class,
        'due_date' => 'date',
        'original_due_date' => 'date',
        'progress_percent' => 'integer',
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function assignedByDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assigned_by_department_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id')->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id')->withTrashed();
    }

    public function currentAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_assignee_user_id')->withTrashed();
    }

    public function responsibleOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id')->withTrashed();
    }

    public function currentReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_reviewer_user_id')->withTrashed();
    }

    public function finalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_approver_user_id')->withTrashed();
    }

    public function workflowSteps(): HasMany
    {
        return $this->hasMany(AssignmentWorkflowStep::class)->orderBy('sequence');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(AssignmentParticipant::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function unassignments(): HasMany
    {
        return $this->hasMany(TaskUnassignment::class)->latest('unassigned_at');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignedToOrganizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'assigned_to_organizational_unit_id');
    }

    public function assignedToDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assigned_to_department_id');
    }

    public function firstViewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_viewed_by_user_id')->withTrashed();
    }

    public function views(): HasMany
    {
        return $this->hasMany(AssignmentView::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function workstream(): BelongsTo
    {
        return $this->belongsTo(Workstream::class);
    }

    public function histories(): HasMany
    {
        // Chronological (oldest first); id breaks same-second ties so the
        // order of rapid consecutive entries is always stable.
        return $this->hasMany(TaskHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceAttachment::class)->orderBy('uploaded_at');
    }

    public function mailRecord(): HasOne
    {
        return $this->hasOne(MailRecord::class);
    }

    public function forwardingRecord(): HasOne
    {
        return $this->hasOne(MailRecord::class, 'routing_task_id');
    }

    /**
     * Overdue is always derived, never stored (PRD §13.3): due date passed
     * while the task is not Completed or Archived.
     */
    protected function overdue(): Attribute
    {
        return Attribute::get(fn () => $this->due_date !== null
            && ! $this->workflow_status->isClosed()
            && $this->due_date->isBefore(today()));
    }

    public function daysOverdue(): int
    {
        if (! $this->overdue) {
            return 0;
        }

        return max(1, (int) $this->due_date->diffInDays(today()));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereDate('due_date', '<', today())
            ->whereNotIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value]);
    }

    /**
     * Match every entered keyword against the task title, its linked subject
     * (workstream), or any explicitly supplied task columns. Keeping this on
     * the model makes the home search and task-list filter behave identically.
     *
     * @param  list<string>  $additionalColumns
     */
    public function scopeMatchingKeywords(Builder $query, string $term, array $additionalColumns = []): Builder
    {
        $keywords = array_values(array_unique(array_filter(
            preg_split('/\s+/u', trim($term)) ?: [],
            fn (string $keyword) => $keyword !== '',
        )));

        foreach ($keywords as $keyword) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $keyword).'%';

            $query->where(function (Builder $match) use ($like, $additionalColumns) {
                $match->where('title', 'like', $like)
                    ->orWhereHas('workstream', fn (Builder $workstream) => $workstream->where('name', 'like', $like));

                foreach ($additionalColumns as $column) {
                    $match->orWhere($column, 'like', $like);
                }
            });
        }

        return $query;
    }
}
