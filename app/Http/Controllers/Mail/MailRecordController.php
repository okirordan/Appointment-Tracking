<?php

namespace App\Http\Controllers\Mail;

use App\Enums\CorrespondenceLifecycleStatus;
use App\Enums\CorrespondenceStatus;
use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreMailRequest;
use App\Http\Requests\Mail\TransitionMailRequest;
use App\Http\Requests\Mail\UpdateIncomingMailRequest;
use App\Http\Requests\Mail\UpdateMailRequest;
use App\Models\Department;
use App\Models\MailRecord;
use App\Models\Workstream;
use App\Services\DepartmentAccessService;
use App\Services\Mail\MailRecordPresenter;
use App\Services\Mail\MailRecordService;
use App\Services\Mail\RecipientSearchService;
use App\Services\SecretaryOfficeScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class MailRecordController extends Controller
{
    public function __construct(
        private MailRecordService $service,
        private MailRecordPresenter $presenter,
        private SecretaryOfficeScope $secretaryOffices,
        private RecipientSearchService $recipients,
        private DepartmentAccessService $departments,
    ) {}

    public function incoming(Request $request): Response
    {
        $this->authorize('viewAny', MailRecord::class);

        return $this->render($request, 'incoming');
    }

    public function outgoing(Request $request): Response
    {
        $this->authorize('viewAny', MailRecord::class);

        return $this->render($request, 'outgoing');
    }

    public function show(Request $request, MailRecord $mail): Response
    {
        // CORR-ACCESS: a friendly, specific message replaces the framework
        // 403 screen when someone without registry access follows a direct
        // URL to original correspondence.
        abort_unless(
            $request->user()->can('view', $mail),
            403,
            'You do not have permission to view this correspondence.',
        );

        $mail->loadMissing('correspondence');
        $direction = $mail->direction;

        if (
            $direction === 'incoming'
            && $mail->correspondence !== null
            && ! $mail->correspondence->current_status->isActiveIncoming()
        ) {
            $direction = 'outgoing';
        }

        return $this->render($request, $direction, $mail);
    }

    public function storeIncoming(StoreMailRequest $request): RedirectResponse
    {
        return $this->store($request, 'incoming');
    }

    public function storeOutgoing(StoreMailRequest $request): RedirectResponse
    {
        return $this->store($request, 'outgoing');
    }

    public function updateIncoming(UpdateIncomingMailRequest $request, MailRecord $mail): RedirectResponse
    {
        $this->service->updateIncoming($request->user(), $mail, $request->validated());

        return redirect()->route('mail.show', $mail)
            ->with('success', "Incoming mail {$mail->register_number} updated. The changes were added to its edit history.");
    }

    public function update(UpdateMailRequest $request, MailRecord $mail): RedirectResponse
    {
        $this->service->update($request->user(), $mail, $request->validated());

        return redirect()->route('mail.show', $mail)
            ->with('success', "Correspondence {$mail->register_number} updated. The changes were added to its audit trail.");
    }

    public function transition(TransitionMailRequest $request, MailRecord $mail): RedirectResponse
    {
        $status = CorrespondenceStatus::from($request->validated('status'));
        $this->service->transition($request->user(), $mail, $status, $request->validated());

        return redirect()->route('mail.show', $mail)
            ->with('success', "{$mail->register_number} marked {$status->label()}.");
    }

    private function store(StoreMailRequest $request, string $direction): RedirectResponse
    {
        $mail = $this->service->capture(
            $request->user(),
            $direction,
            $request->validated(),
            $request->file('attachments', []),
        );

        $indexRoute = $direction === 'incoming' ? 'mail.incoming.index' : 'mail.outgoing.index';

        return redirect()->route($indexRoute)
            ->with('success', "Mail {$mail->register_number} recorded.");
    }

    private function render(Request $request, string $direction, ?MailRecord $selected = null): Response
    {
        $user = $request->user();
        $officeAttachment = $user->role === Role::Secretary
            ? $user->currentSecretaryAttachment()->with(['supervisor', 'organizationalUnit'])->first()
            : null;
        $registerOfficeName = $officeAttachment?->organizationalUnit?->name
            ?? $officeAttachment?->supervisor?->title
            ?? $user->department?->name
            ?? 'Office of the Permanent Secretary';
        $canViewRegister = $request->user()->can('viewAny', MailRecord::class);
        $canManageRegister = $request->user()->can('create', MailRecord::class);
        $filters = [
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'priority' => (string) $request->query('priority', ''),
            'department_id' => (string) $request->query('department_id', ''),
            'assigned_to_user_id' => (string) $request->query('assigned_to_user_id', ''),
            'financial_year' => (string) $request->query('financial_year', ''),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
        ];

        $query = MailRecord::query()
            ->when(
                $direction === 'incoming',
                fn ($mail) => $mail->where('direction', 'incoming')
                    ->where(function ($active) {
                        $active->whereHas('correspondence', fn ($correspondence) => $correspondence
                            ->whereIn('current_status', [
                                CorrespondenceLifecycleStatus::Incoming->value,
                                CorrespondenceLifecycleStatus::UnderReview->value,
                            ]))
                            ->orWhere(function ($legacy) {
                                $legacy->whereNull('correspondence_id')
                                    ->whereIn('status', ['received', 'registered', 'awaiting_review']);
                            });
                    }),
                fn ($mail) => $mail->where(function ($outgoing) {
                    $outgoing->where(function ($registeredOutgoing) {
                        $registeredOutgoing->where('direction', 'outgoing')->whereNull('source_mail_record_id');
                    })->orWhere(function ($forwardedIncoming) {
                        $forwardedIncoming->where('direction', 'incoming')
                            ->whereHas('correspondence', fn ($correspondence) => $correspondence
                                ->whereNotIn('current_status', [
                                    CorrespondenceLifecycleStatus::Incoming->value,
                                    CorrespondenceLifecycleStatus::UnderReview->value,
                                ]));
                    });
                }),
            )
            ->with(['correspondence.recipients', 'correspondence.forwards', 'task.department', 'routingTask.department', 'department', 'organizationalUnit'])
            ->orderByDesc($direction === 'incoming' ? 'received_date' : 'updated_at')
            ->orderByDesc('id');
        if ($request->user()->role === Role::Secretary) {
            $this->secretaryOffices->applyMail($query, $request->user());
        } elseif ($request->user()->role === Role::Commissioner) {
            $this->departments->applyMail($query, $request->user());
        }

        // A correspondence participant may open a direct link, but that does
        // not grant access to either full registry. Keep the surrounding list
        // and lazy-loaded summary data limited to the authorised record.
        if (! $canViewRegister) {
            $query->whereKey($selected?->id ?? 0);
        }

        if ($filters['q'] !== '') {
            $query->matchingKeywords($filters['q']);
        }
        if ($filters['status'] !== '') {
            if ($filters['status'] === 'unassigned') {
                $query->whereNull('task_id');
            } elseif ($filters['status'] === 'assigned_any') {
                $query->whereNotNull('task_id');
            } elseif ($filters['status'] === 'assigned') {
                $query->whereNotNull('task_id')->whereHas('task', fn ($task) => $task->whereNotIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value]));
            } elseif ($filters['status'] === 'completed') {
                $query->where(fn ($scope) => $scope
                    ->whereIn('status', ['completed', 'archived'])
                    ->orWhereHas('task', fn ($task) => $task->whereIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value])));
            } else {
                $query->where('status', $filters['status']);
            }
        }
        if ($filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
        }
        if ($filters['department_id'] !== '') {
            $query->where(function ($department) use ($filters) {
                $department->where('department_id', $filters['department_id'])
                    ->orWhere(function ($legacy) use ($filters) {
                        $legacy->whereNull('department_id')
                            ->whereHas('task', fn ($task) => $task->where('department_id', $filters['department_id']));
                    });
            });
        }
        if ($filters['assigned_to_user_id'] !== '') {
            $query->whereHas('task', fn ($task) => $task
                ->where(function ($responsible) use ($filters) {
                    $responsible
                        ->where('assigned_to_user_id', $filters['assigned_to_user_id'])
                        ->orWhereHas('participants', fn ($participant) => $participant
                            ->where('user_id', $filters['assigned_to_user_id'])
                            ->where('participant_type', 'assignee')
                            ->where('active', true));
                }));
        }
        if ($filters['financial_year'] !== '') {
            $query->where('financial_year', $filters['financial_year']);
        }
        if ($filters['date_from'] !== '') {
            $direction === 'incoming'
                ? $query->whereDate('received_date', '>=', $filters['date_from'])
                : $query->where(function ($date) use ($filters) {
                    $date->where(function ($registered) use ($filters) {
                        $registered->where('direction', 'outgoing')
                            ->whereDate('sent_date', '>=', $filters['date_from']);
                    })->orWhere(function ($forwarded) use ($filters) {
                        $forwarded->where('direction', 'incoming')
                            ->whereHas('correspondence', fn ($correspondence) => $correspondence
                                ->whereDate('last_activity_at', '>=', $filters['date_from']));
                    });
                });
        }
        if ($filters['date_to'] !== '') {
            $direction === 'incoming'
                ? $query->whereDate('received_date', '<=', $filters['date_to'])
                : $query->where(function ($date) use ($filters) {
                    $date->where(function ($registered) use ($filters) {
                        $registered->where('direction', 'outgoing')
                            ->whereDate('sent_date', '<=', $filters['date_to']);
                    })->orWhere(function ($forwarded) use ($filters) {
                        $forwarded->where('direction', 'incoming')
                            ->whereHas('correspondence', fn ($correspondence) => $correspondence
                                ->whereDate('last_activity_at', '<=', $filters['date_to']));
                    });
                });
        }

        $mails = function () use ($query) {
            $page = (clone $query)->paginate(15)->withQueryString();

            return [
                'data' => collect($page->items())->map(fn (MailRecord $mail) => $this->presenter->row($mail))->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ];
        };

        $stats = function () use ($request, $canViewRegister, $selected) {
            $user = $request->user();
            $selectedId = ! $canViewRegister ? $selected?->id : null;
            $key = $selectedId === null
                ? "ats:mail:stats:{$user->id}"
                : "ats:mail:stats:{$user->id}:mail:{$selectedId}";

            return Cache::flexible($key, [30, 180], function () use ($user, $selectedId) {
                $receivedBase = MailRecord::query()->where('direction', 'incoming');
                $incomingBase = MailRecord::query()->where('direction', 'incoming')
                    ->where(function ($active) {
                        $active->whereHas('correspondence', fn ($correspondence) => $correspondence
                            ->whereIn('current_status', ['incoming', 'under_review']))
                            ->orWhere(fn ($legacy) => $legacy->whereNull('correspondence_id')
                                ->whereIn('status', ['received', 'registered', 'awaiting_review']));
                    });
                $outgoingBase = MailRecord::query()->where(function ($outgoing) {
                    $outgoing->where(fn ($registered) => $registered
                        ->where('direction', 'outgoing')->whereNull('source_mail_record_id'))
                        ->orWhere(fn ($forwarded) => $forwarded
                            ->where('direction', 'incoming')
                            ->whereHas('correspondence', fn ($correspondence) => $correspondence
                                ->whereNotIn('current_status', ['incoming', 'under_review'])));
                });
                if ($user->role === Role::Secretary) {
                    $this->secretaryOffices->applyMail($receivedBase, $user);
                    $this->secretaryOffices->applyMail($incomingBase, $user);
                    $this->secretaryOffices->applyMail($outgoingBase, $user);
                } elseif ($user->role === Role::Commissioner) {
                    $this->departments->applyMail($receivedBase, $user);
                    $this->departments->applyMail($incomingBase, $user);
                    $this->departments->applyMail($outgoingBase, $user);
                }
                if ($selectedId !== null) {
                    $receivedBase->whereKey($selectedId);
                    $incomingBase->whereKey($selectedId);
                    $outgoingBase->whereKey($selectedId);
                }

                return [
                    'incoming_total' => (clone $incomingBase)->count(),
                    'received_total' => (clone $receivedBase)->count(),
                    'awaiting_assignment' => (clone $incomingBase)->whereNull('task_id')->whereIn('status', ['received', 'registered'])->count(),
                    'assigned_total' => (clone $outgoingBase)->whereHas('correspondence', fn ($correspondence) => $correspondence->where('current_status', 'action_required'))->count(),
                    'active_assignments' => (clone $outgoingBase)->whereNotNull('task_id')->whereHas('task', fn ($task) => $task->whereNotIn('workflow_status', [TaskStatus::Completed->value, TaskStatus::Archived->value]))->count(),
                    'outgoing_total' => $outgoingBase->count(),
                    'drafts' => (clone $outgoingBase)->whereIn('status', ['draft', 'rejected'])->count(),
                    'awaiting_review' => (clone $outgoingBase)->where('status', 'awaiting_review')->count(),
                    'completed_archived' => (clone $incomingBase)->whereIn('status', ['completed', 'archived'])->count()
                        + (clone $outgoingBase)->whereIn('status', ['completed', 'archived'])->count(),
                ];
            });
        };

        return Inertia::render('mail/index', [
            'direction' => $direction,
            'registerOfficeName' => $registerOfficeName,
            'canViewRegister' => $canViewRegister,
            'canManageRegister' => $canManageRegister,
            'filters' => $filters,
            'stats' => $stats,
            'mails' => $mails,
            'selectedMail' => $selected === null ? null : [
                ...$this->presenter->detail($selected),
                'can_assign' => $request->user()->can('assign', $selected),
                'can_edit' => $request->user()->can('update', $selected),
                'can_participate' => $request->user()->can('participate', $selected),
                'can_approve' => $request->user()->role === Role::Ps,
                'can_unassign' => ($selectedTask = $selected->task ?? $selected->routingTask) !== null
                    && $request->user()->can('unassign', $selectedTask),
            ],
            'priorityOptions' => collect(Priority::cases())->map(fn ($priority) => ['value' => $priority->value, 'label' => $priority->label()])->all(),
            'statusOptions' => collect(CorrespondenceStatus::forDirection($direction))
                ->map(fn ($status) => ['value' => $status->value, 'label' => $status->label()])
                ->all(),
            'financialYearOptions' => fn () => ! $canViewRegister ? [] : Cache::flexible(
                'ats:mail:financial-years',
                [600, 3600],
                fn () => MailRecord::query()->whereNotNull('financial_year')->distinct()->orderByDesc('financial_year')->pluck('financial_year')->all(),
            ),
            'departmentOptions' => fn () => ! $canViewRegister ? [] : Department::query()
                ->where('active', true)
                ->when(
                    ($user->role === Role::Secretary
                        && $officeAttachment?->supervisor?->role !== Role::Ps)
                    || $user->role === Role::Commissioner,
                    fn ($query) => $query->whereIn(
                        'id',
                        $user->role === Role::Commissioner
                            ? $this->departments->currentDepartmentIds($user)
                            : array_filter([
                                $officeAttachment?->organizationalUnit?->department_id
                                    ?? $officeAttachment?->supervisor?->department_id
                                    ?? $user->department_id,
                            ]),
                    ),
                )
                ->orderBy('name')
                ->get(['id', 'name']),
            'officerOptions' => fn () => ! $canViewRegister ? [] : $this->recipients->assignableUsers($user)
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'title']),
            'workstreamOptions' => fn () => $canManageRegister
                ? Workstream::where('active', true)->orderBy('name')->get(['id', 'name', 'type'])
                : [],
        ]);
    }
}
