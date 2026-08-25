import AppShell from '@/components/ats/app-shell';
import { OverdueTag, StatusBadge } from '@/components/ats/badges';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import ProgressBar from '@/components/ats/progress-bar';
import { BellRing, CalendarDays, ChevronDown, Clock3, Eye, EyeOff, Inbox, Mail, Plus, UserRoundCheck } from '@/components/icons';
import type { SharedData, TaskRow } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';

interface Props {
    identity: {
        full_name: string;
        official_job_title: string;
        office_name: string;
        supervisor_name: string;
        supervisor_title: string | null;
        starts_at_label: string;
        ends_at_label: string | null;
        delegated_permissions: string[];
    };
    stats: {
        total: number;
        completed: number;
        overdue: number;
        active: number;
        awaiting_supervisor: number;
        incoming: number;
        outgoing: number;
        drafts: number;
        correspondence_awaiting_action: number;
        forwarded_assigned: number;
        correspondence_completed: number;
    };
    follow_ups: TaskRow[];
    assignment_queue: TaskRow[];
    correspondence: Array<{
        id: number;
        direction: 'incoming' | 'outgoing';
        register_number: string;
        sender_name: string;
        recipient_name: string;
        subject: string;
        mail_date_label: string;
        status: string;
        status_class: string;
    }>;
    schedule: Array<{
        id: number;
        type: string;
        title: string;
        notes: string | null;
        starts_at_label: string;
        ends_at_label: string | null;
    }>;
    office_notifications: Array<{
        id: string;
        message: string;
        detail: string | null;
        time_label: string;
        task_id: number | null;
        severity: 'urgent' | 'warning' | 'info';
        kind: 'supervisor' | 'unhandled' | 'outstanding' | 'notification';
    }>;
    can_create_assignment: boolean;
    can_manage_mail: boolean;
}

export default function SecretaryOfficeDashboard(props: Props) {
    const [scheduleOpen, setScheduleOpen] = useState(false);
    const userId = usePage<SharedData>().props.auth.user?.id ?? 'guest';
    const hiddenStorageKey = `ats:department-work:hidden-reminders:${userId}`;
    const [hiddenNotificationIds, setHiddenNotificationIds] = useState<string[]>([]);
    const visibleNotifications = useMemo(
        () => props.office_notifications.filter((item) => !hiddenNotificationIds.includes(item.id)),
        [hiddenNotificationIds, props.office_notifications],
    );

    useEffect(() => setHiddenNotificationIds(readHiddenNotifications(hiddenStorageKey)), [hiddenStorageKey]);

    const storeHiddenNotifications = (ids: string[]) => {
        setHiddenNotificationIds(ids);
        try {
            window.localStorage.setItem(hiddenStorageKey, JSON.stringify(ids));
        } catch {
            // The dismissal still works for this visit when storage is unavailable.
        }
    };

    const hideNotification = (id: string) => storeHiddenNotifications(Array.from(new Set([...hiddenNotificationIds, id])));
    const clearNotifications = () => storeHiddenNotifications(props.office_notifications.map((item) => item.id));
    const restoreNotifications = () => storeHiddenNotifications([]);

    return (
        <AppShell title={`${props.identity.office_name} Dashboard`}>
            <section className="secretary-office-hero">
                <div>
                    <div className="secretary-office-kicker">
                        <UserRoundCheck aria-hidden="true" />
                        Current office attachment
                    </div>
                    <h1>{props.identity.full_name}</h1>
                    <p className="secretary-office-title">{props.identity.official_job_title}</p>
                    <p className="secretary-office-name">{props.identity.office_name}</p>
                </div>
                <div className="secretary-office-actions">
                    <button type="button" className="btn btn-ghost" onClick={() => setScheduleOpen(true)}>
                        <CalendarDays aria-hidden="true" /> Add meeting or reminder
                    </button>
                    {props.can_create_assignment && (
                        <button type="button" className="btn btn-primary" onClick={() => router.get(route('tasks.index'))}>
                            <Plus aria-hidden="true" /> New assignment
                        </button>
                    )}
                    {props.can_manage_mail && (
                        <button type="button" className="btn btn-primary" onClick={() => router.get(route('mail.incoming.index'))}>
                            <Mail aria-hidden="true" /> Manage correspondence
                        </button>
                    )}
                </div>
            </section>

            <div className="secretary-metric-grid">
                <Metric label="Incoming correspondence" value={props.stats.incoming} />
                <Metric label="Outgoing correspondence" value={props.stats.outgoing} />
                <Metric label="Draft correspondence" value={props.stats.drafts} />
                <Metric label="Awaiting action" value={props.stats.correspondence_awaiting_action} warning />
                <Metric label="Forwarded or assigned" value={props.stats.forwarded_assigned} />
                <Metric label="Completed or archived" value={props.stats.correspondence_completed} />
            </div>

            <div className="secretary-work-rows">
                <CollapsibleWorkRow
                    title="Meetings and deadlines"
                    icon={<CalendarDays aria-hidden="true" />}
                    count={props.schedule.length}
                    defaultOpen
                    actions={
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => setScheduleOpen(true)}>
                            <Plus aria-hidden="true" /> Add
                        </button>
                    }
                >
                    {props.schedule.length === 0 ? (
                        <EmptyState>No upcoming meetings, deadlines or reminders.</EmptyState>
                    ) : (
                        <div className="secretary-schedule-list">
                            {props.schedule.map((item) => (
                                <article key={item.id}>
                                    <div className="secretary-schedule-date">{item.starts_at_label}</div>
                                    <div>
                                        <span>{item.type}</span>
                                        <strong>{item.title}</strong>
                                        {item.notes && <p>{item.notes}</p>}
                                    </div>
                                    <button
                                        type="button"
                                        className="btn btn-ghost btn-sm"
                                        onClick={() => router.delete(route('secretary.schedule.destroy', item.id), { preserveScroll: true })}
                                    >
                                        Done
                                    </button>
                                </article>
                            ))}
                        </div>
                    )}
                </CollapsibleWorkRow>

                <CollapsibleWorkRow
                    title="Office notifications and reminders"
                    icon={<BellRing aria-hidden="true" />}
                    count={visibleNotifications.length}
                    className="secretary-notification-panel"
                    actions={
                        visibleNotifications.length > 0 || hiddenNotificationIds.length > 0 ? (
                            <>
                                {visibleNotifications.length > 0 && (
                                    <button type="button" className="btn btn-ghost btn-sm" onClick={clearNotifications}>
                                        <EyeOff aria-hidden="true" /> Clear all
                                    </button>
                                )}
                                {hiddenNotificationIds.length > 0 && (
                                    <button type="button" className="btn btn-ghost btn-sm" onClick={restoreNotifications}>
                                        <Eye aria-hidden="true" /> Show hidden
                                    </button>
                                )}
                            </>
                        ) : null
                    }
                >
                    {visibleNotifications.length === 0 ? (
                        <EmptyState>
                            {props.office_notifications.length === 0
                                ? 'No outstanding office assignments or notifications.'
                                : 'All reminders are hidden. Use “Show hidden” to restore them.'}
                        </EmptyState>
                    ) : (
                        <div className="secretary-notification-list">
                            {visibleNotifications.map((item) => (
                                <article key={item.id} className={`secretary-notification-item ${item.severity}`}>
                                    <button
                                        type="button"
                                        className="secretary-notification-open"
                                        onClick={() => item.task_id && router.get(route('tasks.show', item.task_id))}
                                        disabled={item.task_id === null}
                                    >
                                        <BellRing aria-hidden="true" />
                                        <span>
                                            <span className="secretary-notification-kind">{notificationKindLabel(item.kind)}</span>
                                            <strong>{item.message}</strong>
                                            {item.detail && <small>{item.detail}</small>}
                                        </span>
                                        <time>{item.time_label}</time>
                                    </button>
                                    <button
                                        type="button"
                                        className="secretary-notification-hide"
                                        onClick={() => hideNotification(item.id)}
                                        aria-label={`Hide reminder: ${item.message}`}
                                        title="Hide this reminder"
                                    >
                                        <EyeOff aria-hidden="true" />
                                    </button>
                                </article>
                            ))}
                        </div>
                    )}
                </CollapsibleWorkRow>

                <CollapsibleWorkRow
                    title="Correspondence"
                    icon={<Mail aria-hidden="true" />}
                    count={props.correspondence.length}
                    actions={<Link href={route('correspondence.index')}>Open workspace</Link>}
                >
                    {props.correspondence.length === 0 ? (
                        <EmptyState>No correspondence currently matches this office attachment.</EmptyState>
                    ) : (
                        <div className="secretary-correspondence-list">
                            {props.correspondence.map((mail) => (
                                <Link key={mail.id} href={route('mail.show', mail.id)} className="secretary-correspondence-item">
                                    <span className={`secretary-direction ${mail.direction}`}>{mail.direction}</span>
                                    <div>
                                        <strong>{mail.subject}</strong>
                                        <small>
                                            {mail.register_number} · {mail.direction === 'incoming' ? mail.sender_name : mail.recipient_name} ·{' '}
                                            {mail.mail_date_label}
                                        </small>
                                    </div>
                                    <StatusBadge label={mail.status} badgeClass={mail.status_class} />
                                </Link>
                            ))}
                        </div>
                    )}
                </CollapsibleWorkRow>

                <TaskPanel title="Actions and follow-ups" icon={<Clock3 aria-hidden="true" />} tasks={props.follow_ups} />
                <TaskPanel title="Assignments in queue" icon={<Inbox aria-hidden="true" />} tasks={props.assignment_queue} />
            </div>

            {scheduleOpen && <ScheduleModal onClose={() => setScheduleOpen(false)} />}
        </AppShell>
    );
}

function notificationKindLabel(kind: Props['office_notifications'][number]['kind']): string {
    return {
        supervisor: 'Office action',
        unhandled: 'Not started',
        outstanding: 'Outstanding',
        notification: 'Office update',
    }[kind];
}

function readHiddenNotifications(storageKey: string): string[] {
    if (typeof window === 'undefined') return [];

    try {
        const stored = JSON.parse(window.localStorage.getItem(storageKey) ?? '[]');
        return Array.isArray(stored) ? stored.filter((value): value is string => typeof value === 'string') : [];
    } catch {
        return [];
    }
}

function CollapsibleWorkRow({
    title,
    icon,
    count,
    defaultOpen = false,
    actions,
    className = '',
    children,
}: {
    title: string;
    icon: ReactNode;
    count: number;
    defaultOpen?: boolean;
    actions?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    const [isOpen, setIsOpen] = useState(defaultOpen);

    return (
        <details
            className={`card secretary-panel secretary-work-row secretary-collapsible ${className}`.trim()}
            open={isOpen}
            onToggle={(event) => setIsOpen(event.currentTarget.open)}
        >
            <summary className="card-hd">
                <h3>
                    {icon} {title}
                </h3>
                <span className="secretary-collapsible-summary-meta">
                    <span className="secretary-panel-count">{count}</span>
                    <ChevronDown aria-hidden="true" />
                </span>
            </summary>
            <div className="secretary-collapsible-body">
                {actions && <div className="secretary-collapsible-actions">{actions}</div>}
                {children}
            </div>
        </details>
    );
}

function Metric({ label, value, warning = false }: { label: string; value: number; warning?: boolean }) {
    return (
        <div className={`secretary-metric${warning ? 'warning' : ''}`}>
            <span>{label}</span>
            <strong>{value}</strong>
        </div>
    );
}

function TaskPanel({ title, icon, tasks }: { title: string; icon: React.ReactNode; tasks: TaskRow[] }) {
    return (
        <CollapsibleWorkRow title={title} icon={icon} count={tasks.length} actions={<Link href={route('tasks.index')}>View all</Link>}>
            {tasks.length === 0 ? (
                <EmptyState>No assignments in this queue.</EmptyState>
            ) : (
                <div className="secretary-task-list">
                    {tasks.map((task) => (
                        <Link key={task.id} href={route('tasks.show', task.id)}>
                            <div>
                                <span>{task.reference}</span>
                                <strong>{task.title}</strong>
                                <small>
                                    {task.assigned_to_name} · Due {task.due_label}
                                </small>
                            </div>
                            <div className="secretary-task-status">
                                {task.overdue && <OverdueTag>{task.days_overdue_label}</OverdueTag>}
                                <StatusBadge label={task.status} badgeClass={task.status_class} />
                                <ProgressBar percent={task.progress} variant={task.progress_class} />
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </CollapsibleWorkRow>
    );
}

function ScheduleModal({ onClose }: { onClose: () => void }) {
    const form = useForm({
        type: 'meeting',
        title: '',
        notes: '',
        starts_at: '',
        ends_at: '',
    });

    return (
        <Modal
            title="Add office schedule item"
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
                        onClick={() => form.post(route('secretary.schedule.store'), { preserveScroll: true, onSuccess: onClose })}
                    >
                        <CalendarDays aria-hidden="true" /> Add to schedule
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={form.errors} />
            <div className="two-col">
                <div className="field">
                    <label htmlFor="schedule-type">Type *</label>
                    <select id="schedule-type" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                        <option value="meeting">Meeting</option>
                        <option value="deadline">Deadline</option>
                        <option value="reminder">Reminder</option>
                    </select>
                </div>
                <div className="field">
                    <label htmlFor="schedule-title">Title *</label>
                    <input id="schedule-title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />
                </div>
            </div>
            <div className="two-col">
                <div className="field">
                    <label htmlFor="schedule-start">Starts *</label>
                    <input
                        id="schedule-start"
                        type="datetime-local"
                        value={form.data.starts_at}
                        onChange={(event) => form.setData('starts_at', event.target.value)}
                    />
                </div>
                <div className="field">
                    <label htmlFor="schedule-end">Ends</label>
                    <input
                        id="schedule-end"
                        type="datetime-local"
                        value={form.data.ends_at}
                        onChange={(event) => form.setData('ends_at', event.target.value)}
                    />
                </div>
            </div>
            <div className="field">
                <label htmlFor="schedule-notes">Notes</label>
                <textarea id="schedule-notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} />
            </div>
        </Modal>
    );
}
