import AnnotationTitlePicker, { type AnnotationTitleOption } from '@/components/ats/annotation-title-picker';
import AnnotationTitleRoutingFields from '@/components/ats/annotation-title-routing-fields';
import AppShell from '@/components/ats/app-shell';
import { OverdueTag, PriorityBadge, StatusBadge } from '@/components/ats/badges';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import Pagination from '@/components/ats/pagination';
import ProgressBar from '@/components/ats/progress-bar';
import { SearchLoader } from '@/components/ats/search-loader';
import Slideover from '@/components/ats/slideover';
import { Timeline, TimelineItem } from '@/components/ats/timeline';
import {
    Activity,
    Archive,
    Building2,
    CalendarDays,
    Check,
    ClipboardList,
    Download,
    ExternalLink,
    Eye,
    FileText,
    FolderKanban,
    FolderPlus,
    Forward,
    Image as ImageIcon,
    Link2,
    Mail,
    MessageSquarePlus,
    Network,
    Paperclip,
    Plus,
    ShieldCheck,
    Trash2,
    UserCheck,
    UserMinus,
    UserRound,
    Video,
} from '@/components/icons';
import { useConfirm } from '@/hooks/use-confirm';
import { cn } from '@/lib/utils';
import type { PaginatedData, SelectOption, TaskDetail, TaskEvidence, TaskRow } from '@/types';
import { router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

interface Filters {
    q: string;
    status: string;
    priority: string;
    department: string;
}

interface UpdateStatusOption extends SelectOption {
    suggested_progress: number;
}

interface AssigneeSuggestion {
    id: number;
    key: string;
    target_type: 'individual' | 'office' | 'department';
    full_name: string;
    title: string | null;
    department_id: number | null;
    initials: string;
}

interface Props {
    pageTitle: string;
    newTaskLabel: string;
    canCreate: boolean;
    filters: Filters;
    showDeptFilter: boolean;
    scopedTotal: number;
    tasks: PaginatedData<TaskRow>;
    statusOptions: SelectOption[];
    priorityOptions: SelectOption[];
    workstreamOptions: { id: number; name: string; type: string }[];
    createdWorkstreamId: number | null;
    departmentOptions: { id: number; name: string }[];
    updateStatusOptions: UpdateStatusOption[];
    selectedTask: TaskDetail | null;
}

export default function TasksIndex(props: Props) {
    const { pageTitle, newTaskLabel, canCreate, filters, scopedTotal, tasks, selectedTask } = props;
    const [showNewTask, setShowNewTask] = useState(false);

    const applyFilters = (changes: Partial<Filters>) => {
        router.get(route('tasks.index'), { ...filters, ...changes }, { preserveState: true, preserveScroll: true });
    };

    const openTask = (id: number) => {
        router.get(route('tasks.show', id), {}, { preserveState: true, preserveScroll: true });
    };

    const closeTask = () => {
        router.get(route('tasks.index'), {}, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppShell title={pageTitle}>
            <div className="page-hd">
                <div>
                    <h1>{pageTitle}</h1>
                    <div className="page-sub">
                        {tasks.meta.total} of {scopedTotal} shown
                    </div>
                </div>
                {canCreate && (
                    <button type="button" className="btn btn-primary" onClick={() => setShowNewTask(true)}>
                        <Plus aria-hidden="true" />
                        New {newTaskLabel}
                    </button>
                )}
            </div>

            <FiltersBar {...props} onApply={applyFilters} />

            <div className="card">
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Title</th>
                                <th>Assigned To</th>
                                <th>Priority</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tasks.data.map((task) => (
                                <tr
                                    key={task.id}
                                    className="row"
                                    tabIndex={0}
                                    onClick={() => openTask(task.id)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            openTask(task.id);
                                        }
                                    }}
                                >
                                    <td className="ref">{task.reference}</td>
                                    <td>{task.title}</td>
                                    <td>{task.assigned_to_name}</td>
                                    <td>
                                        <PriorityBadge label={task.priority} badgeClass={task.priority_class} />
                                    </td>
                                    <td>
                                        {task.due_label}
                                        {task.overdue && <OverdueTag> · overdue</OverdueTag>}
                                    </td>
                                    <td>
                                        <StatusBadge label={task.status} badgeClass={task.status_class} />
                                    </td>
                                    <td style={{ minWidth: 90 }}>
                                        <ProgressBar percent={task.progress} variant={task.progress_class} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {tasks.data.length === 0 && <EmptyState>No tasks match your filters</EmptyState>}
                <Pagination meta={tasks.meta} />
            </div>

            {selectedTask !== null && <TaskSlideover task={selectedTask} updateStatusOptions={props.updateStatusOptions} onClose={closeTask} />}

            {showNewTask && (
                <NewTaskModal
                    label={newTaskLabel}
                    priorityOptions={props.priorityOptions}
                    workstreamOptions={props.workstreamOptions}
                    createdWorkstreamId={props.createdWorkstreamId}
                    onClose={() => setShowNewTask(false)}
                />
            )}
        </AppShell>
    );
}

function FiltersBar(props: Props & { onApply: (changes: Partial<Filters>) => void }) {
    const { filters, statusOptions, priorityOptions, departmentOptions, showDeptFilter, onApply } = props;
    const [q, setQ] = useState(filters.q);

    useEffect(() => setQ(filters.q), [filters.q]);

    return (
        <div className="filters-bar">
            <input
                className="input"
                style={{ width: 260 }}
                type="text"
                placeholder="Search title, subject, reference…"
                aria-label="Search tasks by title, subject, reference or assignee"
                value={q}
                onChange={(event) => setQ(event.target.value)}
                onBlur={() => q !== filters.q && onApply({ q })}
                onKeyDown={(event) => event.key === 'Enter' && onApply({ q })}
            />
            <select
                className="select"
                aria-label="Status filter"
                value={filters.status}
                onChange={(event) => onApply({ status: event.target.value })}
            >
                <option value="">All statuses</option>
                {statusOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
                <option value="overdue">Overdue</option>
            </select>
            <select
                className="select"
                aria-label="Priority filter"
                value={filters.priority}
                onChange={(event) => onApply({ priority: event.target.value })}
            >
                <option value="">All priorities</option>
                {priorityOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {showDeptFilter && (
                <select
                    className="select"
                    aria-label="Department filter"
                    value={filters.department}
                    onChange={(event) => onApply({ department: event.target.value })}
                >
                    <option value="">All departments</option>
                    {departmentOptions.map((department) => (
                        <option key={department.id} value={String(department.id)}>
                            {department.name}
                        </option>
                    ))}
                </select>
            )}
            <button type="button" className="btn btn-ghost" onClick={() => onApply({ q: '', status: '', priority: '', department: '' })}>
                Clear
            </button>
        </div>
    );
}

function TaskSlideover({ task, updateStatusOptions, onClose }: { task: TaskDetail; updateStatusOptions: UpdateStatusOption[]; onClose: () => void }) {
    const [activeTab, setActiveTab] = useState<'overview' | 'workflow' | 'evidence' | 'activity'>('overview');
    const [preview, setPreview] = useState<TaskEvidence | null>(null);

    return (
        <Slideover
            size="wide"
            className="assignment-detail-drawer"
            onClose={onClose}
            header={
                <div className="task-view-heading">
                    <div className="task-view-eyebrow">
                        <span>Assignment details</span>
                        <span>{task.reference}</span>
                    </div>
                    <h2>{task.title}</h2>
                    <div className="task-view-badges">
                        <StatusBadge label={task.status} badgeClass={task.status_class} />
                        <PriorityBadge label={task.priority} badgeClass={task.priority_class} />
                        <span className="task-view-due">
                            <CalendarDays aria-hidden="true" /> Due {task.due_label}
                        </span>
                        {task.overdue && <OverdueTag>{task.days_overdue_label} overdue</OverdueTag>}
                    </div>
                </div>
            }
        >
            <nav className="task-view-tabs" aria-label="Task detail sections">
                <button type="button" className={activeTab === 'overview' ? 'active' : ''} onClick={() => setActiveTab('overview')}>
                    <ClipboardList aria-hidden="true" />
                    Overview
                </button>
                <button type="button" className={activeTab === 'evidence' ? 'active' : ''} onClick={() => setActiveTab('evidence')}>
                    <Paperclip aria-hidden="true" />
                    Evidence
                    <span>{task.evidence.length}</span>
                </button>
                <button type="button" className={activeTab === 'workflow' ? 'active' : ''} onClick={() => setActiveTab('workflow')}>
                    <Network aria-hidden="true" />
                    Workflow
                    <span>{task.workflow_route.length}</span>
                </button>
                <button type="button" className={activeTab === 'activity' ? 'active' : ''} onClick={() => setActiveTab('activity')}>
                    <Activity aria-hidden="true" />
                    Activity
                    <span>{task.history.length + task.annotations.length}</span>
                </button>
            </nav>

            {activeTab === 'overview' && (
                <div className="task-view-panel">
                    {task.department_support && (
                        <section className="department-support-notice" aria-label="Department Secretary access">
                            <span className="department-support-icon" aria-hidden="true">
                                <ShieldCheck />
                            </span>
                            <div>
                                <span>Department coordination access</span>
                                <strong>Supporting {task.department_support.department_name}</strong>
                                <p>
                                    You can view the full forwarding trail, record progress, and delegate to departmental officers. Every action
                                    remains attributed to {task.department_support.secretary_name}.
                                </p>
                            </div>
                            <span className="department-support-officer">On behalf of {task.department_support.current_officer_name}</span>
                        </section>
                    )}
                    <div className="task-overview-layout">
                        <div className="task-overview-main">
                            <section className="task-progress-card">
                                <div className="task-progress-copy">
                                    <div>
                                        <span>Current progress</span>
                                        <strong>{task.progress}%</strong>
                                    </div>
                                    <StatusBadge label={task.status} badgeClass={task.status_class} />
                                </div>
                                <ProgressBar percent={task.progress} variant={task.progress_class} />
                                <div className="task-progress-foot">
                                    <span>0%</span>
                                    <span>Completion</span>
                                    <span>100%</span>
                                </div>
                            </section>

                            <section className="card task-description-card">
                                <div className="task-card-heading">
                                    <span className="task-card-icon">
                                        <ClipboardList aria-hidden="true" />
                                    </span>
                                    <div>
                                        <div className="section-title">Assignment brief</div>
                                        <span>What needs to be delivered</span>
                                    </div>
                                </div>
                                <p>{task.description}</p>
                                {task.initial_instruction && (
                                    <div className="task-instruction">
                                        <strong>Initial instruction</strong>
                                        <span>{task.initial_instruction}</span>
                                    </div>
                                )}
                            </section>

                            {task.mail_origin && (
                                <section className="card task-mail-origin-card">
                                    <div className="task-card-heading">
                                        <span className="task-card-icon">
                                            <Mail aria-hidden="true" />
                                        </span>
                                        <div>
                                            <div className="section-title">Incoming mail source</div>
                                            <span>
                                                {task.mail_origin.register_number} · received {task.mail_origin.received_date_label}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="task-mail-origin-copy">
                                        <div>
                                            <span>From</span>
                                            <strong>{task.mail_origin.sender_name}</strong>
                                        </div>
                                        <div>
                                            <span>To</span>
                                            <strong>{task.mail_origin.recipient_name}</strong>
                                        </div>
                                        <div>
                                            <span>Sender reference</span>
                                            <strong>{task.mail_origin.correspondence_reference ?? 'Not provided'}</strong>
                                        </div>
                                        <div>
                                            <span>Source files</span>
                                            <strong>{task.mail_origin.attachment_count}</strong>
                                        </div>
                                    </div>
                                    {task.mail_origin.attachments.length > 0 && (
                                        <div className="task-mail-source-files" aria-label="Original correspondence documents">
                                            <span className="result-eyebrow">Original documents</span>
                                            {task.mail_origin.attachments.map((attachment) => (
                                                <a key={attachment.id} href={attachment.download_url} className="task-mail-source-file">
                                                    <Paperclip aria-hidden="true" />
                                                    <span>
                                                        <strong>{attachment.filename}</strong>
                                                        <small>{attachment.size_label}</small>
                                                    </span>
                                                    <Download aria-hidden="true" />
                                                </a>
                                            ))}
                                        </div>
                                    )}
                                    {task.mail_origin.mail_url && (
                                        <a className="btn btn-ghost task-mail-origin-link" href={task.mail_origin.mail_url}>
                                            Open original correspondence <ExternalLink aria-hidden="true" />
                                        </a>
                                    )}
                                    {task.mail_origin.forwarding_record_url && (
                                        <a className="btn btn-ghost task-mail-origin-link" href={task.mail_origin.forwarding_record_url}>
                                            Open outgoing routing record {task.mail_origin.forwarding_record_number}{' '}
                                            <ExternalLink aria-hidden="true" />
                                        </a>
                                    )}
                                </section>
                            )}
                        </div>

                        <aside className="task-details-card" aria-label="Assignment information">
                            <div className="task-details-heading">
                                <span className="result-eyebrow">Assignment information</span>
                                <h3>Key details</h3>
                            </div>
                            <div className="task-details-list">
                                <TaskDetailItem icon={<UserRound />} label="Assigned to" value={task.assigned_to_name} emphasized />
                                <TaskDetailItem
                                    icon={<Building2 />}
                                    label="Assignment type"
                                    value={`${task.assignment_target_type.replace('_', ' ')} · ${task.assignment_target_label}`}
                                />
                                <TaskDetailItem
                                    icon={<Eye />}
                                    label="Viewing status"
                                    value={
                                        task.first_viewed_at
                                            ? `${task.viewing_status} · ${task.first_viewed_by ?? 'Recipient'} · ${task.first_viewed_at}`
                                            : task.viewing_status
                                    }
                                    emphasized={task.viewing_status === 'Not Viewed' || task.viewing_status === 'Overdue'}
                                />
                                <TaskDetailItem icon={<UserCheck />} label="Assigned by" value={task.assigned_by_name} />
                                <TaskDetailItem icon={<CalendarDays />} label="Due date" value={task.due_label} emphasized={task.overdue} />
                                <TaskDetailItem icon={<Building2 />} label="Department" value={task.department_name} />
                                <TaskDetailItem
                                    icon={<Network />}
                                    label="Division"
                                    value={task.division_name ?? 'Not specified'}
                                    muted={!task.division_name}
                                />
                                <TaskDetailItem
                                    icon={<FolderKanban />}
                                    label="Project, programme or subject"
                                    value={task.workstream_name ?? 'Not specified'}
                                    muted={!task.workstream_name}
                                />
                            </div>
                        </aside>
                    </div>

                    {task.can_update_progress && <ProgressForm task={task} updateStatusOptions={updateStatusOptions} />}
                </div>
            )}

            {activeTab === 'evidence' && <EvidenceSection task={task} onPreview={setPreview} />}

            {activeTab === 'workflow' && <WorkflowSection task={task} />}

            {activeTab === 'activity' && (
                <div className="task-activity-grid">
                    <section className="card task-activity-card">
                        <div className="section-title">Progress history</div>
                        <Timeline>
                            {task.history.map((entry) => (
                                <TimelineItem
                                    key={entry.id}
                                    text={
                                        <>
                                            <strong>{entry.status ?? entry.action_type}</strong>
                                            {entry.note ? ` — ${entry.note}` : ''}
                                            {(entry.origin_title || entry.recipient_title) && (
                                                <span className="annotation-routing">
                                                    {entry.origin_title && (
                                                        <span>
                                                            <strong>From:</strong> {entry.origin_title}
                                                        </span>
                                                    )}
                                                    {entry.recipient_title && (
                                                        <span>
                                                            <strong>To:</strong> {entry.recipient_title}
                                                        </span>
                                                    )}
                                                </span>
                                            )}
                                        </>
                                    }
                                    meta={`${entry.by}${entry.on_behalf_of ? ` · on behalf of ${entry.on_behalf_of}${entry.on_behalf_of_title ? `, ${entry.on_behalf_of_title}` : ''}` : ''} · ${entry.when_label}`}
                                />
                            ))}
                        </Timeline>
                        {task.history.length === 0 && <EmptyState>No progress history yet.</EmptyState>}
                    </section>
                    <section className="card task-activity-card">
                        <AnnotationsSection task={task} />
                    </section>
                </div>
            )}

            {preview !== null && <EvidenceViewer evidence={preview} onClose={() => setPreview(null)} />}
        </Slideover>
    );
}

function TaskDetailItem({
    icon,
    label,
    value,
    emphasized = false,
    muted = false,
}: {
    icon: ReactNode;
    label: string;
    value: string;
    emphasized?: boolean;
    muted?: boolean;
}) {
    return (
        <div className={cn('task-detail-item', emphasized && 'emphasized', muted && 'muted')}>
            <span className="task-detail-icon" aria-hidden="true">
                {icon}
            </span>
            <div>
                <span>{label}</span>
                <strong>{value}</strong>
            </div>
        </div>
    );
}

function EvidenceIcon({ kind }: { kind: TaskEvidence['preview_kind'] }) {
    if (kind === 'image') return <ImageIcon aria-hidden="true" />;
    if (kind === 'video') return <Video aria-hidden="true" />;
    if (kind === 'link') return <Link2 aria-hidden="true" />;

    return <FileText aria-hidden="true" />;
}

function EvidenceSection({ task, onPreview }: { task: TaskDetail; onPreview: (evidence: TaskEvidence) => void }) {
    return (
        <section className="task-view-panel">
            <div className="task-section-heading">
                <div>
                    <span className="result-eyebrow">Supporting material</span>
                    <h3>Evidence library</h3>
                    <p>Preview documents and media securely without leaving the assignment.</p>
                </div>
                <span className="badge">
                    {task.evidence.length} item{task.evidence.length === 1 ? '' : 's'}
                </span>
            </div>

            {task.evidence.length === 0 ? (
                <div className="card">
                    <EmptyState>No evidence has been attached yet.</EmptyState>
                </div>
            ) : (
                <div className="task-evidence-grid">
                    {task.evidence.map((item) => (
                        <article className="task-evidence-card" key={item.id}>
                            <div className={`task-evidence-icon kind-${item.preview_kind}`}>
                                <EvidenceIcon kind={item.preview_kind} />
                            </div>
                            <div className="task-evidence-copy">
                                <strong>{item.filename}</strong>
                                <span>
                                    {item.size_label} · {item.uploaded_by}
                                </span>
                                <small>{item.when_label}</small>
                            </div>
                            <div className="task-evidence-actions">
                                {item.preview_url !== null && (
                                    <button type="button" className="btn btn-ghost" onClick={() => onPreview(item)}>
                                        <Eye aria-hidden="true" />
                                        Preview
                                    </button>
                                )}
                                <a
                                    className="btn btn-ghost"
                                    href={item.download_url}
                                    target={item.source_type === 'link' ? '_blank' : undefined}
                                    rel={item.source_type === 'link' ? 'noreferrer' : undefined}
                                >
                                    {item.source_type === 'link' ? <ExternalLink aria-hidden="true" /> : <Download aria-hidden="true" />}
                                    {item.source_type === 'link' ? 'Open link' : 'Download'}
                                </a>
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
}

function EvidenceViewer({ evidence, onClose }: { evidence: TaskEvidence; onClose: () => void }) {
    return (
        <Modal
            size="wide"
            title={evidence.filename}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Close
                    </button>
                    <a className="btn btn-primary" href={evidence.download_url}>
                        <Download aria-hidden="true" />
                        Download
                    </a>
                </>
            }
        >
            <div className={`evidence-preview evidence-preview-${evidence.preview_kind}`}>
                {evidence.preview_kind === 'image' && evidence.preview_url && <img src={evidence.preview_url} alt={evidence.filename} />}
                {evidence.preview_kind === 'video' && evidence.preview_url && (
                    <video controls preload="metadata">
                        <source src={evidence.preview_url} type={evidence.mime_type} />
                        Your browser cannot play this video.
                    </video>
                )}
                {(evidence.preview_kind === 'pdf' || evidence.preview_kind === 'document') && evidence.preview_url && (
                    <iframe src={evidence.preview_url} title={`Preview of ${evidence.filename}`} />
                )}
            </div>
        </Modal>
    );
}

function ProgressForm({ task, updateStatusOptions }: { task: TaskDetail; updateStatusOptions: UpdateStatusOption[] }) {
    const confirm = useConfirm();
    const { data, setData, transform, post, processing, errors } = useForm<{
        status: string;
        progress: number;
        note: string;
        evidence: File[];
        evidence_links: string[];
    }>({
        status: task.status_value,
        progress: task.progress,
        note: '',
        evidence: [],
        evidence_links: [''],
    });

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        // Marking Completed is a significant status change — confirm intent.
        if (data.status === 'completed') {
            const ok = await confirm({
                title: `Mark ${task.reference} as completed?`,
                message: 'This submits the task as complete with the attached files or links and notifies the assigning supervisor.',
                confirmLabel: 'Mark completed',
            });
            if (!ok) {
                return;
            }
        }

        transform((current) => ({
            ...current,
            evidence_links: current.evidence_links.map((link) => link.trim()).filter((link) => link !== ''),
        }));
        post(route('tasks.progress.store', task.id), { forceFormData: true, preserveScroll: true });
    };

    const onStatusChange = (value: string) => {
        const option = updateStatusOptions.find((item) => item.value === value);
        setData((current) => ({
            ...current,
            status: value,
            progress: option !== undefined ? option.suggested_progress : current.progress,
        }));
    };

    const updateLink = (index: number, value: string) => {
        setData(
            'evidence_links',
            data.evidence_links.map((link, linkIndex) => (linkIndex === index ? value : link)),
        );
    };

    const removeLink = (index: number) => {
        const next = data.evidence_links.filter((_, linkIndex) => linkIndex !== index);
        setData('evidence_links', next.length === 0 ? [''] : next);
    };

    return (
        <form className="card task-progress-form" onSubmit={submit}>
            <div className="task-form-heading">
                <div>
                    <span className="result-eyebrow">{task.department_support ? 'Department support update' : 'Officer update'}</span>
                    <h3>Record progress</h3>
                </div>
                <span>
                    {task.department_support
                        ? `Recorded by ${task.department_support.secretary_name} on behalf of ${task.department_support.current_officer_name}.`
                        : 'Files and links are kept with this assignment.'}
                </span>
            </div>
            <div className="two-col">
                <div className="field">
                    <label htmlFor="progress-status">Status</label>
                    <select id="progress-status" value={data.status} onChange={(event) => onStatusChange(event.target.value)}>
                        {updateStatusOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    {errors.status && <div className="field-error">{errors.status}</div>}
                </div>
                <div className="field">
                    <label htmlFor="progress-percent">Progress %</label>
                    <input
                        id="progress-percent"
                        type="number"
                        min={0}
                        max={100}
                        value={data.progress}
                        onChange={(event) => setData('progress', Math.max(0, Math.min(100, Number(event.target.value) || 0)))}
                    />
                    {errors.progress && <div className="field-error">{errors.progress}</div>}
                </div>
            </div>
            <div className="field task-form-field">
                <label htmlFor="progress-note">Progress Note (required)</label>
                <textarea
                    id="progress-note"
                    placeholder="Describe what changed…"
                    value={data.note}
                    onChange={(event) => setData('note', event.target.value)}
                />
                {errors.note && <div className="field-error">{errors.note}</div>}
            </div>
            <div className="task-evidence-inputs">
                <div className="field task-upload-field">
                    <label htmlFor="progress-evidence">Upload files</label>
                    <input
                        id="progress-evidence"
                        type="file"
                        multiple
                        accept=".pdf,.docx,.xlsx,.pptx,.jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.mov,.m4v,image/*,video/mp4,video/webm,video/quicktime"
                        onChange={(event) => setData('evidence', Array.from(event.target.files ?? []))}
                    />
                    <span className="field-help">Documents, images or video. Evidence is required when completing an assignment.</span>
                    {(errors.evidence ?? (errors as Record<string, string>)['evidence.0']) && (
                        <div className="field-error">{errors.evidence ?? (errors as Record<string, string>)['evidence.0']}</div>
                    )}
                </div>
                <div className="field task-link-field">
                    <div className="task-link-label">
                        <label htmlFor="progress-evidence-link-0">Evidence links</label>
                        {data.evidence_links.length < 5 && (
                            <button type="button" onClick={() => setData('evidence_links', [...data.evidence_links, ''])}>
                                <Plus aria-hidden="true" /> Add link
                            </button>
                        )}
                    </div>
                    {data.evidence_links.map((link, index) => (
                        <div className="task-link-input" key={index}>
                            <Link2 aria-hidden="true" />
                            <input
                                id={`progress-evidence-link-${index}`}
                                type="url"
                                placeholder="https://example.org/evidence"
                                value={link}
                                onChange={(event) => updateLink(index, event.target.value)}
                            />
                            {(data.evidence_links.length > 1 || link !== '') && (
                                <button type="button" onClick={() => removeLink(index)} aria-label={`Remove evidence link ${index + 1}`}>
                                    <Trash2 aria-hidden="true" />
                                </button>
                            )}
                        </div>
                    ))}
                    {(errors.evidence_links ?? (errors as Record<string, string>)['evidence_links.0']) && (
                        <div className="field-error">{errors.evidence_links ?? (errors as Record<string, string>)['evidence_links.0']}</div>
                    )}
                </div>
            </div>
            <button type="submit" className="btn btn-primary task-submit-update" disabled={processing}>
                <Check aria-hidden="true" />
                Submit Update
            </button>
        </form>
    );
}

function AnnotationsSection({ task }: { task: TaskDetail }) {
    const [originTitle, setOriginTitle] = useState<AnnotationTitleOption | null>(null);
    const [recipientTitle, setRecipientTitle] = useState<AnnotationTitleOption | null>(null);
    const { data, setData, post, processing, errors, reset } = useForm({
        text: '',
        origin_title_id: '' as number | '',
        recipient_title_id: '' as number | '',
    });

    const submit = () => {
        post(route('tasks.annotations.store', task.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOriginTitle(null);
                setRecipientTitle(null);
            },
        });
    };

    return (
        <div>
            <div className="section-title">Annotations</div>
            {task.annotations.map((annotation) => (
                <div key={annotation.id} className="annotation" style={{ marginBottom: 8 }}>
                    <div style={{ fontWeight: 600, fontSize: 12 }}>
                        {annotation.author}{' '}
                        <span style={{ color: 'var(--label)', fontWeight: 400 }}>
                            {annotation.author_role ? `· ${annotation.author_role} ` : ''}· {annotation.when_label}
                        </span>
                    </div>
                    {(annotation.origin_title || annotation.recipient_title) && (
                        <div className="annotation-routing">
                            {annotation.origin_title && (
                                <span>
                                    <strong>From:</strong> {annotation.origin_title}
                                </span>
                            )}
                            {annotation.recipient_title && (
                                <span>
                                    <strong>To:</strong> {annotation.recipient_title}
                                </span>
                            )}
                        </div>
                    )}
                    <div className="annotation-text" style={{ marginTop: 3 }}>
                        {annotation.text}
                    </div>
                </div>
            ))}
            {task.annotations.length === 0 && <EmptyState style={{ padding: 16 }}>No annotations yet</EmptyState>}
            {task.can_annotate && (
                <div className="field" style={{ marginTop: 10 }}>
                    <div className="annotation-title-grid">
                        <AnnotationTitlePicker
                            label="From — Officer Title"
                            selected={originTitle}
                            onSelect={(title) => {
                                setOriginTitle(title);
                                setData('origin_title_id', title?.id ?? '');
                            }}
                            hint="Optional originating officer title."
                            error={errors.origin_title_id}
                        />
                        <AnnotationTitlePicker
                            label="To — Officer Title"
                            selected={recipientTitle}
                            onSelect={(title) => {
                                setRecipientTitle(title);
                                setData('recipient_title_id', title?.id ?? '');
                            }}
                            hint="Optional receiving officer title."
                            error={errors.recipient_title_id}
                        />
                    </div>
                    <textarea
                        aria-label="Add an annotation"
                        placeholder="Add an instruction or comment…"
                        value={data.text}
                        onChange={(event) => setData('text', event.target.value)}
                    />
                    {errors.text && <div className="field-error">{errors.text}</div>}
                    <button
                        type="button"
                        className="btn btn-ghost"
                        style={{ marginTop: 8 }}
                        disabled={processing || data.text.trim() === ''}
                        onClick={submit}
                    >
                        <MessageSquarePlus aria-hidden="true" />
                        {processing ? 'Saving annotation…' : 'Add Annotation'}
                    </button>
                </div>
            )}
        </div>
    );
}

function WorkflowSection({ task }: { task: TaskDetail }) {
    const [action, setAction] = useState<'delegate' | 'submit' | 'review' | 'reassign' | 'unassign' | null>(null);

    return (
        <div className="task-view-panel">
            <section className="workflow-ownership-grid" aria-label="Assignment ownership">
                {[
                    ['Creator', task.ownership.creator],
                    ['Owner', task.ownership.owner],
                    ['Current holder', task.ownership.current_assignee],
                    ['Responsible officer', task.ownership.responsible_officer],
                    ['Current reviewer', task.ownership.current_reviewer ?? 'Not awaiting review'],
                    ['Final approver', task.ownership.final_approver ?? 'Defined by actual route'],
                ].map(([label, value]) => (
                    <div key={label} className="workflow-owner-card">
                        <span>{label}</span>
                        <strong>{value}</strong>
                    </div>
                ))}
            </section>

            <section className="card workflow-status-strip">
                <div>
                    <span>Execution</span>
                    <strong>{task.execution_status}</strong>
                </div>
                <div>
                    <span>Review</span>
                    <strong>{task.review_status}</strong>
                </div>
                <div>
                    <span>Approval</span>
                    <strong>{task.approval_status}</strong>
                </div>
            </section>

            <section className="card assignment-route-card">
                <div className="task-card-heading">
                    <span className="task-card-icon">
                        <Network aria-hidden="true" />
                    </span>
                    <div>
                        <h3>Actual delegation route</h3>
                        <p>Reporting follows this route in reverse. Skipped organizational levels are not inserted automatically.</p>
                    </div>
                </div>
                <ol className="assignment-route">
                    {task.workflow_route.map((step) => (
                        <li
                            key={step.id}
                            className={cn(
                                'assignment-route-step',
                                step.is_current && 'current',
                                step.status_value === 'returned' && 'returned',
                                step.status_value === 'approved' && 'completed',
                                step.is_skipped && 'skipped',
                            )}
                        >
                            <div className="assignment-route-marker">{step.sequence}</div>
                            <div className="assignment-route-content">
                                <div className="assignment-route-title">
                                    <strong>
                                        {step.sender_name} → {step.recipient_name}
                                    </strong>
                                    <span
                                        className={`badge ${step.is_current ? 'st-inprogress' : step.status_value === 'approved' ? 'st-completed' : 'st-received'}`}
                                    >
                                        {step.status}
                                    </span>
                                    {step.is_direct && <span className="badge pr-high">Direct route</span>}
                                    {step.recipient_inactive && <span className="badge pr-urgent">Former / inactive</span>}
                                </div>
                                <div className="assignment-route-meta">
                                    {step.position_name ?? step.role_name ?? 'Position not recorded'} · Assigned {step.assigned_at} · Due{' '}
                                    {step.due_at}
                                </div>
                                {step.instructions && <p>{step.instructions}</p>}
                                {step.review_decision && (
                                    <div className="assignment-route-review">
                                        <strong>{step.review_decision}</strong>
                                        {step.reviewer_comments ? ` — ${step.reviewer_comments}` : ''}
                                    </div>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
            </section>

            {task.withdrawal_history.length > 0 && (
                <section className="card withdrawal-history-card">
                    <div className="task-card-heading">
                        <span className="task-card-icon withdrawal-history-icon">
                            <UserMinus aria-hidden="true" />
                        </span>
                        <div>
                            <h3>Withdrawal and resolution history</h3>
                            <p>Previous responsibility, resolution, and remarks remain permanently visible.</p>
                        </div>
                    </div>
                    <ol className="withdrawal-history-list">
                        {task.withdrawal_history.map((entry) => (
                            <li key={entry.id}>
                                <div className="withdrawal-history-heading">
                                    <strong>{entry.previous_assignee}</strong>
                                    <span>{entry.resolution ?? 'Awaiting resolution'}</span>
                                </div>
                                <p>{entry.reason}</p>
                                {entry.new_assignee && (
                                    <div className="withdrawal-history-destination">
                                        <Forward aria-hidden="true" /> New responsible officer: <strong>{entry.new_assignee}</strong>
                                    </div>
                                )}
                                {entry.resolution_note && <small>Remarks: {entry.resolution_note}</small>}
                                {entry.comments && <small>Additional context: {entry.comments}</small>}
                                <time>
                                    Withdrawn by {entry.withdrawn_by} · {entry.withdrawn_at}
                                </time>
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            {task.pending_submission && (
                <section className="card pending-review-card">
                    <div>
                        <span>Pending review</span>
                        <strong>
                            {task.pending_submission.submitted_by}
                            {task.pending_submission.submitted_by_title ? `, ${task.pending_submission.submitted_by_title}` : ''} ·{' '}
                            {task.pending_submission.submitted_at}
                        </strong>
                        <p>{task.pending_submission.note}</p>
                    </div>
                    {task.can_review && (
                        <button type="button" className="btn btn-primary" onClick={() => setAction('review')}>
                            <UserCheck aria-hidden="true" /> Review submission
                        </button>
                    )}
                </section>
            )}

            <div className="workflow-actions">
                {task.can_delegate && (
                    <button type="button" className="btn btn-primary" onClick={() => setAction('delegate')}>
                        <Network aria-hidden="true" /> Delegate onward
                    </button>
                )}
                {task.can_submit && (
                    <button type="button" className="btn btn-primary" onClick={() => setAction('submit')}>
                        <Check aria-hidden="true" /> Submit work upward
                    </button>
                )}
                {task.can_reassign && (
                    <button type="button" className="btn btn-ghost" onClick={() => setAction('reassign')}>
                        <UserRound aria-hidden="true" /> Reassign current step
                    </button>
                )}
                {task.can_unassign && (
                    <button type="button" className="btn btn-ghost danger-button" onClick={() => setAction('unassign')}>
                        <UserMinus aria-hidden="true" /> Unassign task
                    </button>
                )}
            </div>

            {task.review_history.length > 0 && (
                <section className="card" style={{ padding: 18 }}>
                    <div className="section-title">Review and approval history</div>
                    <Timeline>
                        {task.review_history.map((review) => (
                            <TimelineItem
                                key={review.id}
                                text={
                                    <>
                                        <strong>
                                            {review.decision} — {review.reviewer}
                                            {review.reviewer_title ? `, ${review.reviewer_title}` : ''}
                                        </strong>
                                        <div>{review.comments}</div>
                                    </>
                                }
                                meta={review.when_label}
                            />
                        ))}
                    </Timeline>
                </section>
            )}

            {action === 'delegate' && <DelegateModal task={task} onClose={() => setAction(null)} />}
            {action === 'submit' && <SubmitModal task={task} onClose={() => setAction(null)} />}
            {action === 'review' && task.pending_submission && (
                <ReviewModal task={task} submissionId={task.pending_submission.id} onClose={() => setAction(null)} />
            )}
            {action === 'reassign' && <ReassignModal task={task} onClose={() => setAction(null)} />}
            {action === 'unassign' && <UnassignModal task={task} onClose={() => setAction(null)} />}
        </div>
    );
}

function DelegateModal({ task, onClose }: { task: TaskDetail; onClose: () => void }) {
    const form = useForm({ recipient_user_id: '' as string | number, instructions: '', due_at: '', is_direct: false as boolean });
    return (
        <Modal
            title={`Delegate ${task.reference}`}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary"
                        disabled={form.processing}
                        onClick={() => form.post(route('tasks.workflow.delegate', task.id), { preserveScroll: true, onSuccess: onClose })}
                    >
                        <Network aria-hidden="true" /> Delegate
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={form.errors} />
            <AssigneePicker
                onSelect={(id) => form.setData('recipient_user_id', id)}
                departmentOnly={task.department_support !== null}
                error={form.errors.recipient_user_id}
            />
            {task.department_support && (
                <span className="field-help">Only active Commissioners or Officers in {task.department_support.department_name} are shown.</span>
            )}
            <div className="field">
                <label htmlFor="delegate-instructions">Instructions *</label>
                <textarea id="delegate-instructions" value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} />
                {form.errors.instructions && <div className="field-error">{form.errors.instructions}</div>}
            </div>
            <div className="field">
                <label htmlFor="delegate-due">Due date</label>
                <input id="delegate-due" type="date" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
            </div>
            {task.can_direct && (
                <label className="workflow-direct-option">
                    <input type="checkbox" checked={form.data.is_direct} onChange={(e) => form.setData('is_direct', e.target.checked)} />
                    <span>
                        <strong>Direct assignment / skip levels</strong>
                        <small>The recipient will report directly to you unless they delegate onward.</small>
                    </span>
                </label>
            )}
        </Modal>
    );
}

function SubmitModal({ task, onClose }: { task: TaskDetail; onClose: () => void }) {
    const form = useForm({ note: '' });
    return (
        <Modal
            title={`Submit ${task.reference} upward`}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary"
                        disabled={form.processing || !form.data.note.trim()}
                        onClick={() => form.post(route('tasks.workflow.submit', task.id), { preserveScroll: true, onSuccess: onClose })}
                    >
                        <Check aria-hidden="true" /> Submit for review
                    </button>
                </>
            }
        >
            <p style={{ color: 'var(--muted)', marginBottom: 14 }}>
                This will go to the person who delegated the current workflow step—not to skipped hierarchy levels.
            </p>
            <FormErrorSummary errors={form.errors} />
            <div className="field">
                <label htmlFor="submission-note">Submission note *</label>
                <textarea id="submission-note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
            </div>
        </Modal>
    );
}

function ReviewModal({ task, submissionId, onClose }: { task: TaskDetail; submissionId: number; onClose: () => void }) {
    const form = useForm({ decision: 'approve', comments: '', revised_due_at: '' });
    return (
        <Modal
            title={`Review ${task.reference}`}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary"
                        disabled={form.processing || !form.data.comments.trim()}
                        onClick={() => form.post(route('tasks.workflow.review', submissionId), { preserveScroll: true, onSuccess: onClose })}
                    >
                        <UserCheck aria-hidden="true" /> Record decision
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={form.errors} />
            <div className="field">
                <label htmlFor="review-decision">Decision *</label>
                <select id="review-decision" value={form.data.decision} onChange={(e) => form.setData('decision', e.target.value)}>
                    <option value="approve">Approve and forward upward</option>
                    <option value="return">Return for correction</option>
                    <option value="request_information">Request more information</option>
                    <option value="reject">Reject</option>
                </select>
            </div>
            <div className="field">
                <label htmlFor="review-comments">Comments / reason *</label>
                <textarea id="review-comments" value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} />
            </div>
            {form.data.decision === 'return' && (
                <div className="field">
                    <label htmlFor="review-due">Revised deadline</label>
                    <input
                        id="review-due"
                        type="date"
                        value={form.data.revised_due_at}
                        onChange={(e) => form.setData('revised_due_at', e.target.value)}
                    />
                </div>
            )}
        </Modal>
    );
}

function ReassignModal({ task, onClose }: { task: TaskDetail; onClose: () => void }) {
    const form = useForm({ replacement_user_id: '' as string | number, reason: '' });
    return (
        <Modal
            title={`Reassign ${task.reference}`}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary"
                        disabled={form.processing || !form.data.reason.trim()}
                        onClick={() => form.post(route('tasks.workflow.reassign', task.id), { preserveScroll: true, onSuccess: onClose })}
                    >
                        <UserRound aria-hidden="true" /> Reassign
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={form.errors} />
            <AssigneePicker onSelect={(id) => form.setData('replacement_user_id', id)} error={form.errors.replacement_user_id} />
            <div className="field">
                <label htmlFor="reassign-reason">Reason *</label>
                <textarea id="reassign-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
            </div>
        </Modal>
    );
}

function UnassignModal({ task, onClose }: { task: TaskDetail; onClose: () => void }) {
    const form = useForm({
        user_ids: task.active_assignees.map((assignee) => assignee.user_id),
        reason: '',
        comments: '',
        resolution: 'reassign' as 'reassign' | 'file',
        replacement_user_id: '' as string | number,
        resolution_note: '',
        filing_category: '',
        confirmed: false as boolean,
    });

    const toggleUser = (userId: number, checked: boolean) => {
        form.setData('user_ids', checked ? [...new Set([...form.data.user_ids, userId])] : form.data.user_ids.filter((id) => id !== userId));
    };

    return (
        <Modal
            title={`Withdraw and resolve ${task.reference}`}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary"
                        disabled={
                            form.processing ||
                            form.data.user_ids.length === 0 ||
                            !form.data.reason.trim() ||
                            (form.data.resolution === 'reassign' && !form.data.replacement_user_id) ||
                            !form.data.confirmed
                        }
                        onClick={() =>
                            form.post(route('tasks.workflow.unassign', task.id), {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        {form.data.resolution === 'reassign' ? <Forward aria-hidden="true" /> : <Archive aria-hidden="true" />}
                        {form.data.resolution === 'reassign' ? 'Withdraw and reassign' : 'Withdraw and file'}
                    </button>
                </>
            }
        >
            <div className="unassignment-warning" role="status">
                <UserMinus aria-hidden="true" />
                <div>
                    <strong>Choose what happens immediately after withdrawal.</strong>
                    <span>
                        The previous officer, withdrawal reason, next destination, remarks, and time are retained in the permanent assignment and mail
                        history.
                    </span>
                </div>
            </div>
            <FormErrorSummary errors={form.errors} />
            <fieldset className="unassignment-user-list">
                <legend>Users to unassign *</legend>
                {task.active_assignees.map((assignee) => (
                    <label key={assignee.user_id} className="unassignment-option">
                        <input
                            type="checkbox"
                            checked={form.data.user_ids.includes(assignee.user_id)}
                            onChange={(event) => toggleUser(assignee.user_id, event.target.checked)}
                        />
                        <span>
                            <strong>{assignee.name}</strong>
                            <small>
                                {assignee.title || 'Officer'} · assigned {assignee.assigned_at}
                            </small>
                        </span>
                    </label>
                ))}
            </fieldset>
            <div className="field">
                <label htmlFor="unassign-reason">Reason for unassignment *</label>
                <textarea
                    id="unassign-reason"
                    required
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    placeholder="State why this assignment is being withdrawn from the selected user(s)."
                />
                {form.errors.reason && <div className="field-error">{form.errors.reason}</div>}
            </div>
            <div className="field">
                <label htmlFor="unassign-comments">Additional comments</label>
                <textarea
                    id="unassign-comments"
                    value={form.data.comments}
                    onChange={(event) => form.setData('comments', event.target.value)}
                    placeholder="Optional context for the audit trail and notification."
                />
            </div>
            <fieldset className="withdrawal-resolution-options">
                <legend>Next step *</legend>
                <label className={form.data.resolution === 'reassign' ? 'selected' : ''}>
                    <input
                        type="radio"
                        name="withdrawal-resolution"
                        value="reassign"
                        checked={form.data.resolution === 'reassign'}
                        onChange={() => form.setData('resolution', 'reassign')}
                    />
                    <span className="withdrawal-resolution-icon">
                        <Forward aria-hidden="true" />
                    </span>
                    <span>
                        <strong>Reassign for action</strong>
                        <small>Transfer responsibility to another eligible officer and continue tracking this assignment.</small>
                    </span>
                </label>
                <label className={form.data.resolution === 'file' ? 'selected' : ''}>
                    <input
                        type="radio"
                        name="withdrawal-resolution"
                        value="file"
                        checked={form.data.resolution === 'file'}
                        onChange={() => {
                            form.setData('resolution', 'file');
                            form.setData(
                                'user_ids',
                                task.active_assignees.map((assignee) => assignee.user_id),
                            );
                        }}
                    />
                    <span className="withdrawal-resolution-icon">
                        <Archive aria-hidden="true" />
                    </span>
                    <span>
                        <strong>Send for filing</strong>
                        <small>Close active action and move the linked correspondence to the filed register.</small>
                    </span>
                </label>
            </fieldset>
            {form.data.resolution === 'reassign' ? (
                <div className="withdrawal-resolution-fields">
                    <AssigneePicker
                        onSelect={(id) => form.setData('replacement_user_id', id)}
                        departmentOnly={task.department_support !== null}
                        error={form.errors.replacement_user_id}
                    />
                    <div className="field">
                        <label htmlFor="withdrawal-resolution-note">Reassignment instruction</label>
                        <textarea
                            id="withdrawal-resolution-note"
                            value={form.data.resolution_note}
                            onChange={(event) => form.setData('resolution_note', event.target.value)}
                            placeholder="Optional instruction or context for the new responsible officer."
                        />
                    </div>
                </div>
            ) : (
                <div className="withdrawal-resolution-fields">
                    <div className="field">
                        <label htmlFor="withdrawal-filing-category">Filing category</label>
                        <input
                            id="withdrawal-filing-category"
                            value={form.data.filing_category}
                            onChange={(event) => form.setData('filing_category', event.target.value)}
                            placeholder="e.g. Completed action, Information only"
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="withdrawal-filing-note">Filing remarks</label>
                        <textarea
                            id="withdrawal-filing-note"
                            value={form.data.resolution_note}
                            onChange={(event) => form.setData('resolution_note', event.target.value)}
                            placeholder="Optional remarks explaining why no further action is required."
                        />
                    </div>
                </div>
            )}
            {form.errors.resolution && <div className="field-error">{form.errors.resolution}</div>}
            <label className="unassignment-confirmation">
                <input type="checkbox" checked={form.data.confirmed} onChange={(event) => form.setData('confirmed', event.target.checked)} />
                <span>I confirm that the selected user(s) should no longer hold or act on this task.</span>
            </label>
        </Modal>
    );
}

function NewTaskModal({
    label,
    priorityOptions,
    workstreamOptions,
    createdWorkstreamId,
    onClose,
}: {
    label: string;
    priorityOptions: SelectOption[];
    workstreamOptions: { id: number; name: string; type: string }[];
    createdWorkstreamId: number | null;
    onClose: () => void;
}) {
    const [showWorkstreamCreator, setShowWorkstreamCreator] = useState(false);
    const [selectedAssignees, setSelectedAssignees] = useState<AssigneeSuggestion[]>([]);
    const [originTitle, setOriginTitle] = useState<AnnotationTitleOption | null>(null);
    const [recipientTitle, setRecipientTitle] = useState<AnnotationTitleOption | null>(null);
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        origin_title_id: '' as number | '',
        recipient_title_id: '' as number | '',
        target_type: 'individual' as 'individual' | 'multiple' | 'office' | 'department',
        organizational_unit_id: '',
        target_department_id: '',
        assigned_to_user_ids: [] as number[],
        priority: 'medium',
        due_date: '',
        instructions: '',
        workstream_id: '' as string | number,
        attachments: [] as File[],
    });
    const workstreamForm = useForm({
        type: 'project',
        name: '',
        code: '',
        description: '',
    });

    useEffect(() => {
        if (createdWorkstreamId !== null) {
            setData('workstream_id', createdWorkstreamId);
        }
    }, [createdWorkstreamId, setData]);

    const submit = () => {
        post(route('tasks.store'), { forceFormData: true, onSuccess: onClose });
    };

    const addAssignee = (user: AssigneeSuggestion) => {
        if (selectedAssignees.some((selected) => selected.key === user.key)) {
            return;
        }
        const isGroup = user.target_type !== 'individual';
        const next = isGroup
            ? [user]
            : [...(selectedAssignees.some((selected) => selected.target_type !== 'individual') ? [] : selectedAssignees), user];
        const individuals = next.filter((selected) => selected.target_type === 'individual');
        setSelectedAssignees(next);
        setData((current) => ({
            ...current,
            target_type: isGroup ? user.target_type : individuals.length > 1 ? 'multiple' : 'individual',
            assigned_to_user_ids: individuals.map((selected) => selected.id),
            organizational_unit_id: user.target_type === 'office' ? String(user.id) : '',
            target_department_id: user.target_type === 'department' ? String(user.id) : '',
        }));
    };

    const removeAssignee = (key: string) => {
        const next = selectedAssignees.filter((selected) => selected.key !== key);
        const individuals = next.filter((selected) => selected.target_type === 'individual');
        setSelectedAssignees(next);
        setData((current) => ({
            ...current,
            target_type: individuals.length > 1 ? 'multiple' : 'individual',
            assigned_to_user_ids: individuals.map((selected) => selected.id),
            organizational_unit_id: '',
            target_department_id: '',
        }));
    };

    const createWorkstream = () => {
        workstreamForm.post(route('workstreams.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                workstreamForm.reset();
                setShowWorkstreamCreator(false);
            },
        });
    };

    return (
        <Modal
            title={`New ${label}`}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="button" className="btn btn-primary" disabled={processing} onClick={submit}>
                        <Check aria-hidden="true" />
                        Create
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={errors} />
            <div className="field">
                <label htmlFor="nt-title">Title *</label>
                <input id="nt-title" type="text" value={data.title} onChange={(event) => setData('title', event.target.value)} />
                {errors.title && <div className="field-error">{errors.title}</div>}
            </div>
            <AnnotationTitleRoutingFields
                origin={originTitle}
                recipient={recipientTitle}
                onOriginSelect={(title) => {
                    setOriginTitle(title);
                    setData('origin_title_id', title?.id ?? '');
                }}
                onRecipientSelect={(title) => {
                    setRecipientTitle(title);
                    setData('recipient_title_id', title?.id ?? '');
                }}
                originError={errors.origin_title_id}
                recipientError={errors.recipient_title_id}
            />
            <AssigneePicker
                onSelect={() => undefined}
                onPickUser={addAssignee}
                includeGroups
                error={errors.assigned_to_user_ids || errors.organizational_unit_id || errors.target_department_id}
            />
            {selectedAssignees.length > 0 && (
                <div className="selected-assignees" aria-label="Selected assignees">
                    {selectedAssignees.map((user) => (
                        <span key={user.key} className="selected-assignee">
                            <span>
                                <strong>{user.full_name}</strong>
                                <small>
                                    {user.title || 'Staff member'} · {user.target_type === 'individual' ? 'Personal' : `Shared ${user.target_type}`}
                                </small>
                            </span>
                            <button type="button" onClick={() => removeAssignee(user.key)} aria-label={`Remove ${user.full_name}`}>
                                <Trash2 aria-hidden="true" />
                            </button>
                        </span>
                    ))}
                </div>
            )}
            <div className="two-col">
                <div className="field">
                    <label htmlFor="nt-priority">Priority</label>
                    <select id="nt-priority" value={data.priority} onChange={(event) => setData('priority', event.target.value)}>
                        {priorityOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="field">
                    <label htmlFor="nt-due">Due Date</label>
                    <input id="nt-due" type="date" value={data.due_date} onChange={(event) => setData('due_date', event.target.value)} />
                    {errors.due_date && <div className="field-error">{errors.due_date}</div>}
                </div>
            </div>
            <div className="field task-workstream-field">
                <div className="task-workstream-label">
                    <label htmlFor="nt-workstream">Project, programme, initiative or subject</label>
                    <button type="button" onClick={() => setShowWorkstreamCreator((current) => !current)}>
                        <FolderPlus aria-hidden="true" />
                        {showWorkstreamCreator ? 'Cancel creation' : 'Create new'}
                    </button>
                </div>
                <select id="nt-workstream" value={data.workstream_id} onChange={(event) => setData('workstream_id', event.target.value)}>
                    <option value="">None</option>
                    {workstreamOptions.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name} ({item.type})
                        </option>
                    ))}
                </select>
                <span className="field-help">Selections are shared across the whole system for future assignments.</span>
                {errors.workstream_id && <div className="field-error">{errors.workstream_id}</div>}
                {showWorkstreamCreator && (
                    <div className="workstream-create-panel">
                        <div className="workstream-create-heading">
                            <div>
                                <strong>Add to the shared list</strong>
                                <span>Names are matched without regard to capitalisation or extra spaces, so duplicates cannot be created.</span>
                            </div>
                        </div>
                        <div className="two-col">
                            <div className="field">
                                <label htmlFor="new-workstream-type">Type</label>
                                <select
                                    id="new-workstream-type"
                                    value={workstreamForm.data.type}
                                    onChange={(event) => workstreamForm.setData('type', event.target.value)}
                                >
                                    <option value="project">Project</option>
                                    <option value="programme">Programme</option>
                                    <option value="initiative">Initiative</option>
                                    <option value="subject">Subject</option>
                                </select>
                            </div>
                            <div className="field">
                                <label htmlFor="new-workstream-code">Code (optional)</label>
                                <input
                                    id="new-workstream-code"
                                    value={workstreamForm.data.code}
                                    onChange={(event) => workstreamForm.setData('code', event.target.value)}
                                    placeholder="e.g. TEP-2026"
                                />
                            </div>
                        </div>
                        <div className="field">
                            <label htmlFor="new-workstream-name">Name *</label>
                            <input
                                id="new-workstream-name"
                                value={workstreamForm.data.name}
                                onChange={(event) => workstreamForm.setData('name', event.target.value)}
                                placeholder="Enter the official name"
                            />
                            {workstreamForm.errors.name && <div className="field-error">{workstreamForm.errors.name}</div>}
                        </div>
                        <div className="field">
                            <label htmlFor="new-workstream-description">Description (optional)</label>
                            <textarea
                                id="new-workstream-description"
                                value={workstreamForm.data.description}
                                onChange={(event) => workstreamForm.setData('description', event.target.value)}
                                placeholder="Briefly describe its purpose"
                            />
                        </div>
                        <button
                            type="button"
                            className="btn btn-ghost workstream-create-action"
                            disabled={workstreamForm.processing || workstreamForm.data.name.trim() === ''}
                            onClick={createWorkstream}
                        >
                            <Plus aria-hidden="true" />
                            Add and select
                        </button>
                    </div>
                )}
            </div>
            <div className="field">
                <label htmlFor="nt-instructions">Instructions</label>
                <textarea
                    id="nt-instructions"
                    placeholder="Initial instructions or annotation…"
                    value={data.instructions}
                    onChange={(event) => setData('instructions', event.target.value)}
                />
            </div>
            <div className="field">
                <label htmlFor="nt-description">Detailed Description</label>
                <textarea id="nt-description" value={data.description} onChange={(event) => setData('description', event.target.value)} />
                {errors.description && <div className="field-error">{errors.description}</div>}
            </div>
            <div className="field">
                <label htmlFor="nt-attachments">Attachments</label>
                <input
                    id="nt-attachments"
                    type="file"
                    multiple
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.txt,.csv"
                    onChange={(event) => setData('attachments', Array.from(event.target.files ?? []))}
                />
                <span className="field-help">Up to 10 files, 20 MB each. Files remain in the permanent task history.</span>
                {errors.attachments && <div className="field-error">{errors.attachments}</div>}
            </div>
        </Modal>
    );
}

function AssigneePicker({
    onSelect,
    onPickUser,
    includeGroups = false,
    departmentOnly = false,
    error,
}: {
    onSelect: (id: number) => void;
    onPickUser?: (user: AssigneeSuggestion) => void;
    includeGroups?: boolean;
    departmentOnly?: boolean;
    error?: string;
}) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [suggestions, setSuggestions] = useState<AssigneeSuggestion[]>([]);
    const [searched, setSearched] = useState(false);
    const [searching, setSearching] = useState(false);
    const debounce = useRef<ReturnType<typeof setTimeout>>(null);

    const search = (term: string) => {
        setQuery(term);
        setOpen(true);
        onSelect(0);
        if (debounce.current !== null) {
            clearTimeout(debounce.current);
        }
        debounce.current = setTimeout(async () => {
            if (term.trim().length < 2) {
                setSuggestions([]);
                setSearched(false);
                setSearching(false);
                return;
            }
            setSearching(true);
            try {
                const response = await fetch(
                    `${route('tasks.assignee-search')}?q=${encodeURIComponent(term.trim())}${includeGroups ? '&include_groups=1' : ''}${departmentOnly ? '&department_only=1' : ''}`,
                    {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    },
                );
                const payload = (await response.json()) as { users: AssigneeSuggestion[] };
                setSuggestions(payload.users);
                setSearched(true);
            } finally {
                setSearching(false);
            }
        }, 220);
    };

    const pick = (user: AssigneeSuggestion) => {
        setQuery(includeGroups ? '' : user.full_name);
        if (includeGroups) setSuggestions([]);
        setOpen(false);
        onSelect(user.id);
        onPickUser?.(user);
    };

    return (
        <div className="field">
            <label htmlFor="nt-assignee">Department/Officer *</label>
            <div className="posrel">
                <input
                    id="nt-assignee"
                    type="text"
                    placeholder={includeGroups ? 'Search officer name or department…' : 'Search officer name…'}
                    autoComplete="off"
                    value={query}
                    onChange={(event) => search(event.target.value)}
                    onFocus={() => setOpen(true)}
                    onBlur={() => setTimeout(() => setOpen(false), 140)}
                />
                {searching && (
                    <span className="assignee-searching">
                        <SearchLoader compact label="Searching staff…" />
                    </span>
                )}
                {open && query.trim().length >= 2 && (
                    <div className="dropdown" style={{ left: 0, right: 0, width: 'auto', top: 'calc(100% + 6px)' }}>
                        {suggestions.map((user) => (
                            <div key={user.key} className="dropdown-item" onMouseDown={() => pick(user)}>
                                <div className="avatar" style={{ width: 28, height: 28, fontSize: 11 }}>
                                    {user.initials}
                                </div>
                                <div className="grow">
                                    <div style={{ fontWeight: 600 }}>{user.full_name}</div>
                                    <div style={{ color: 'var(--label)', fontSize: 11 }}>
                                        {user.title} · {user.target_type === 'individual' ? 'Officer' : user.target_type}
                                    </div>
                                </div>
                            </div>
                        ))}
                        {searched && !searching && suggestions.length === 0 && (
                            <EmptyState style={{ padding: 14 }}>No matching eligible officer or department.</EmptyState>
                        )}
                    </div>
                )}
            </div>
            {error && <div className="field-error">{error}</div>}
        </div>
    );
}
