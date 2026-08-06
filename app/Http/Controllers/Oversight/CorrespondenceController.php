<?php

namespace App\Http\Controllers\Oversight;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Correspondence;
use App\Models\MailRecord;
use App\Models\User;
use App\Services\DepartmentAccessService;
use App\Services\Mail\MailRecordPresenter;
use App\Services\SecretaryOfficeScope;
use App\Services\Tasks\AssignmentTargetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CorrespondenceController extends Controller
{
    public function __construct(
        private MailRecordPresenter $presenter,
        private SecretaryOfficeScope $secretaryOffices,
        private DepartmentAccessService $departments,
        private AssignmentTargetService $targets,
    ) {}

    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();
        abort_if($viewer->role === Role::Sysadmin, 403, 'System administrators cannot access correspondence content.');

        $term = trim((string) $request->query('q', ''));
        $view = (string) $request->query('view', 'all');
        $base = MailRecord::query()
            ->whereIn('mail_records.id', Correspondence::query()->whereNotNull('originating_mail_record_id')->select('originating_mail_record_id'))
            ->withCount('attachments')
            ->with([
                'correspondence' => fn ($correspondence) => $correspondence
                    ->withCount(['updates', 'attachments'])
                    ->with(['recipients.task', 'forwards']),
                'department', 'organizationalUnit', 'task.department',
            ])
            ->orderByDesc(
                Correspondence::query()
                    ->select('last_activity_at')
                    ->whereColumn('correspondences.id', 'mail_records.correspondence_id')
                    ->limit(1),
            )
            ->orderByDesc('mail_records.id');

        if ($viewer->role === Role::Secretary) {
            $this->secretaryOffices->applyMail($base, $viewer);
        } elseif ($this->departments->scopesMail($viewer)) {
            $this->departments->applyMail($base, $viewer);
        } elseif (! $viewer->can('viewAny', MailRecord::class)) {
            $officeIds = $this->targets->officeIdsFor($viewer);
            $departmentIds = $this->targets->departmentIdsFor($viewer);
            $base->whereHas('correspondence', function (Builder $correspondence) use ($viewer, $officeIds, $departmentIds) {
                $correspondence->where(function (Builder $visible) use ($viewer, $officeIds, $departmentIds) {
                    $visible->whereHas('recipients', function (Builder $recipient) use ($viewer, $officeIds, $departmentIds) {
                        $recipient->where('active', true)->where(function (Builder $target) use ($viewer, $officeIds, $departmentIds) {
                            $target->where('user_id', $viewer->id);
                            if ($officeIds !== []) {
                                $target->orWhereIn('organizational_unit_id', $officeIds);
                            }
                            if ($departmentIds !== []) {
                                $target->orWhereIn('department_id', $departmentIds);
                            }
                        });
                    })->orWhereHas('accessGrants', fn (Builder $grant) => $grant
                        ->where('user_id', $viewer->id)->whereNull('revoked_at'));
                });
            });
        }

        $query = clone $base;
        $this->applyView($query, $view, $viewer);
        if ($term !== '') {
            $query->where(function (Builder $match) use ($term) {
                $match->where(fn (Builder $mail) => $mail->matchingKeywords($term))
                    ->orWhereHas('correspondence.recipients', fn (Builder $recipient) => $recipient->where('recipient_name_snapshot', 'like', "%{$term}%"))
                    ->orWhereHas('correspondence.updates', fn (Builder $update) => $update->where('body', 'like', "%{$term}%"))
                    ->orWhereHas('correspondence.attachments', fn (Builder $attachment) => $attachment->where('original_filename', 'like', "%{$term}%"));
            });
        }

        $page = $query->paginate(20)->withQueryString();
        $counts = collect(['all', 'action', 'cc', 'sent', 'awaiting_response', 'responded', 'closed', 'overdue'])
            ->mapWithKeys(function (string $category) use ($base, $viewer) {
                $count = clone $base;
                $this->applyView($count, $category, $viewer);

                return [$category => $count->count()];
            });

        return Inertia::render('oversight/correspondence', [
            'q' => $term,
            'view' => $view,
            'counts' => $counts,
            'items' => [
                'data' => collect($page->items())->map(fn (MailRecord $mail) => [
                    ...$this->presenter->row($mail),
                    'url' => route('mail.show', $mail),
                    'last_activity_label' => $mail->correspondence?->last_activity_at?->format('d/m/Y H:i'),
                    'forwarded_at_label' => $mail->correspondence?->forwards->last()?->forwarded_at?->format('d/m/Y H:i'),
                    'originating_office' => $mail->organizationalUnit?->name ?? $mail->department?->name ?? 'Office of the Permanent Secretary',
                    'action_required' => $mail->correspondence?->recipients
                        ?->contains(fn ($recipient) => $recipient->active && $recipient->purpose === 'action_required') ?? false,
                    'due_date_label' => $mail->correspondence?->recipients
                        ?->where('active', true)->where('purpose', 'action_required')->whereNotNull('due_date')->sortBy('due_date')->first()?->due_date?->format('d/m/Y'),
                    'updates_count' => (int) ($mail->correspondence?->updates_count ?? 0),
                    'attachments_count' => (int) ($mail->correspondence?->attachments_count ?? 0) + (int) $mail->attachments_count,
                    'to_recipients' => $mail->correspondence?->recipients
                        ?->where('active', true)->where('recipient_type', 'to')->pluck('recipient_name_snapshot')->values()->all() ?? [],
                    'cc_recipients' => $mail->correspondence?->recipients
                        ?->where('active', true)->where('recipient_type', 'cc')->pluck('recipient_name_snapshot')->values()->all() ?? [],
                    'my_recipient_type' => $mail->correspondence?->recipients
                        ?->first(fn ($recipient) => $recipient->active && $recipient->user_id === $viewer->id)?->recipient_type,
                ])->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ],
        ]);
    }

    private function applyView(Builder $query, string $view, User $viewer): void
    {
        $officeIds = $this->targets->officeIdsFor($viewer);
        $departmentIds = $this->targets->departmentIdsFor($viewer);
        $visibleRecipient = function (Builder $recipient) use ($viewer, $officeIds, $departmentIds): void {
            $recipient->where('active', true)->where(function (Builder $target) use ($viewer, $officeIds, $departmentIds) {
                $target->where('user_id', $viewer->id);
                if ($officeIds !== []) {
                    $target->orWhereIn('organizational_unit_id', $officeIds);
                }
                if ($departmentIds !== []) {
                    $target->orWhereIn('department_id', $departmentIds);
                }
            });
        };

        match ($view) {
            'action' => $query->whereHas('correspondence.recipients', fn (Builder $recipient) => $recipient
                ->where($visibleRecipient)->where('purpose', 'action_required')),
            'cc' => $query->whereHas('correspondence.recipients', fn (Builder $recipient) => $recipient
                ->where('user_id', $viewer->id)->where('active', true)->where('recipient_type', 'cc')),
            'sent' => $query->whereHas('correspondence.forwards', fn (Builder $forward) => $forward
                ->where(fn (Builder $sender) => $sender->where('forwarded_by_user_id', $viewer->id)
                    ->orWhere('on_behalf_of_user_id', $viewer->id))),
            'awaiting_response' => $query->whereHas('correspondence', fn (Builder $correspondence) => $correspondence->where('current_status', 'awaiting_response')),
            'responded' => $query->whereHas('correspondence', fn (Builder $correspondence) => $correspondence->where('current_status', 'responded')),
            'closed' => $query->whereHas('correspondence', fn (Builder $correspondence) => $correspondence->whereIn('current_status', ['closed', 'withdrawn'])),
            'overdue' => $query->whereHas('correspondence.recipients', fn (Builder $recipient) => $recipient
                ->where($visibleRecipient)
                ->where('purpose', 'action_required')
                ->whereDate('due_date', '<', today())
                ->whereHas('task', fn (Builder $task) => $task
                    ->whereNotIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value]))),
            default => null,
        };
    }
}
