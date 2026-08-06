import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import Pagination from '@/components/ats/pagination';
import ProgressBar from '@/components/ats/progress-bar';
import RecipientPicker, { type RecipientSuggestion } from '@/components/ats/recipient-picker';
import Slideover from '@/components/ats/slideover';
import type { PaginatedData, SelectOption } from '@/types';
import { Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    ChevronDown,
    Download,
    ExternalLink,
    Eye,
    FileText,
    Forward,
    History,
    Inbox,
    LoaderCircle,
    MessageSquarePlus,
    Paperclip,
    Pencil,
    Plus,
    Printer,
    Send,
    ShieldCheck,
    Trash2,
    UserMinus,
    UsersRound,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';

function todayForDateInput(): string {
    const now = new Date();
    const localDate = new Date(now.getTime() - now.getTimezoneOffset() * 60_000);

    return localDate.toISOString().slice(0, 10);
}

interface MailRow {
    id: number;
    direction: 'incoming' | 'outgoing';
    register_number: string;
    sender_name: string;
    recipient_name: string;
    subject: string;
    correspondence_reference: string | null;
    mail_date_label: string;
    activity_date_label: string | null;
    recipient_display: string;
    status: string;
    status_value: string;
    lifecycle_status: string;
    status_class: string;
    priority: string;
    priority_class: string;
    financial_year: string | null;
    department_name: string | null;
    task_reference: string | null;
    record_kind: string;
}

interface MailAttachment {
    id: number | string;
    filename: string;
    mime_type: string;
    size_label: string;
    preview_kind: 'pdf' | 'image' | 'video' | 'document' | 'none';
    preview_url: string | null;
    download_url: string;
    uploaded_by: string;
    uploaded_at_label: string | null;
    correspondence_attachment_id: number | null;
    version_number: number;
}

interface AssignmentUnassignment {
    id: number;
    officer: string;
    unassigned_by: string;
    reason: string;
    unassigned_at_label: string | null;
    originally_assigned_at_label: string | null;
}

interface AssignmentInfo {
    task_id: number;
    reference: string;
    url: string;
    is_withdrawn: boolean;
    status: string;
    status_class: string;
    execution_status: string;
    progress_percent: number;
    assigned_officer: string;
    active_assignees: { user_id: number; name: string; title: string | null; assigned_at_label: string | null }[];
    assigned_by: string;
    instructions: string | null;
    assigned_at_label: string | null;
    due_date_label: string | null;
    is_overdue: boolean;
    completed_at_label: string | null;
    unassignments: AssignmentUnassignment[];
}

interface ActivityEntry {
    id: string;
    source: 'Registry' | 'Assignment' | 'Correspondence';
    action: string;
    performed_by: string;
    performed_at_label: string | null;
    note: string | null;
    changes: { field: string; before: string; after: string }[];
    recipients?: { type: 'to' | 'cc'; purpose: string; name: string }[];
    attachments?: { filename: string; download_url: string }[];
}

interface CorrespondenceRecipientInfo {
    id: number;
    name: string;
    title: string | null;
    purpose: string;
    due_date_label?: string | null;
    task_id?: number | null;
}

interface MailDetail extends MailRow {
    sender_organisation: string | null;
    details: string | null;
    letter_date_label: string;
    receipt_method: string | null;
    confidentiality: string;
    registry_file_number: string | null;
    captured_by: string;
    captured_at_label: string;
    office_name: string;
    office_supervisor_name: string | null;
    prepared_on_behalf_of: string | null;
    last_processed_by: string | null;
    dispatch_method: string | null;
    dispatch_reference: string | null;
    dispatched_at_label: string | null;
    reviewed_by: string | null;
    review_notes: string | null;
    approved_by: string | null;
    assigned_to_name: string | null;
    task_id: number | null;
    task_url: string | null;
    correspondence_id: number | null;
    correspondence_status: string;
    primary_recipients: CorrespondenceRecipientInfo[];
    cc_recipients: CorrespondenceRecipientInfo[];
    source_mail: { id: number; register_number: string; url: string } | null;
    forwarded_records: Array<{ id: number; register_number: string; task_reference: string | null; url: string }>;
    attachments_linked_from_source: boolean;
    attachments: MailAttachment[];
    edit_values: {
        sender_name: string;
        sender_organisation: string;
        recipient_name: string;
        subject: string;
        details: string;
        correspondence_reference: string;
        letter_date: string;
        received_date: string;
        sent_date: string;
        receipt_method: string;
        confidentiality: string;
        registry_file_number: string;
        priority: string;
    };
    assignment: AssignmentInfo | null;
    activity_history: ActivityEntry[];
    can_assign: boolean;
    can_edit: boolean;
    can_participate: boolean;
    can_approve: boolean;
    can_unassign: boolean;
}

interface Props {
    direction: 'incoming' | 'outgoing';
    registerOfficeName: string;
    canViewRegister: boolean;
    canManageRegister: boolean;
    filters: {
        q: string;
        status: string;
        priority: string;
        department_id: string;
        assigned_to_user_id: string;
        financial_year: string;
        date_from: string;
        date_to: string;
    };
    stats: {
        incoming_total: number;
        received_total: number;
        awaiting_assignment: number;
        assigned_total: number;
        active_assignments: number;
        outgoing_total: number;
        drafts: number;
        awaiting_review: number;
        completed_archived: number;
    };
    mails: PaginatedData<MailRow>;
    selectedMail: MailDetail | null;
    priorityOptions: SelectOption[];
    statusOptions: SelectOption[];
    financialYearOptions: string[];
    departmentOptions: { id: number; name: string }[];
    officerOptions: { id: number; full_name: string; title: string }[];
    workstreamOptions: { id: number; name: string; type: string }[];
}

export default function MailIndex(props: Props) {
    const { direction, filters, mails, selectedMail } = props;
    const [showCapture, setShowCapture] = useState(false);
    const [query, setQuery] = useState(filters.q);
    const title = direction === 'incoming' ? 'Active Incoming Correspondence' : 'Outgoing / Forwarded Correspondence';
    const indexRoute = direction === 'incoming' ? 'mail.incoming.index' : 'mail.outgoing.index';

    const applyFilters = (changes: Partial<Props['filters']>) => {
        router.get(
            route(indexRoute),
            {
                ...filters,
                ...changes,
                priority: '',
                assigned_to_user_id: '',
                financial_year: '',
            },
            { preserveState: true, preserveScroll: true, only: ['filters', 'mails'] },
        );
    };

    return (
        <AppShell title={title}>
            <div className="page-hd mail-page-heading">
                <div>
                    <span className="result-eyebrow">{props.registerOfficeName}</span>
                    <h1>{title}</h1>
                    <div className="page-sub">
                        {direction === 'incoming'
                            ? `Items awaiting review or forwarding. ${props.stats.received_total} correspondence record(s) have been received in total.`
                            : 'Forwarded matters and formally registered outgoing correspondence, with their original receipt history preserved.'}
                    </div>
                </div>
                {props.canManageRegister && (
                    <button type="button" className="btn btn-primary" onClick={() => setShowCapture(true)}>
                        <Plus aria-hidden="true" /> Record {direction} correspondence
                    </button>
                )}
            </div>

            <nav className="mail-register-tabs" aria-label="Mail registers">
                <Link href={route('mail.incoming.index')} className={direction === 'incoming' ? 'active' : ''}>
                    <Inbox aria-hidden="true" /> Active Incoming <span>{props.stats.incoming_total}</span>
                </Link>
                <Link href={route('mail.outgoing.index')} className={direction === 'outgoing' ? 'active' : ''}>
                    <Send aria-hidden="true" /> Outgoing / Forwarded <span>{props.stats.outgoing_total}</span>
                </Link>
            </nav>

            <div className="filters-bar mail-filters">
                <input
                    className="input"
                    type="search"
                    aria-label="Search mail"
                    placeholder="Search subject, details, sender, recipient or reference…"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => event.key === 'Enter' && applyFilters({ q: query })}
                />
                <select
                    className="select"
                    value={filters.status}
                    onChange={(event) => applyFilters({ status: event.target.value })}
                    aria-label="Correspondence status"
                >
                    <option value="">All statuses</option>
                    {props.statusOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <select
                    className="select"
                    value={filters.department_id}
                    onChange={(event) => applyFilters({ department_id: event.target.value })}
                    aria-label="Department"
                >
                    <option value="">All departments</option>
                    {props.departmentOptions.map((department) => (
                        <option key={department.id} value={department.id}>
                            {department.name}
                        </option>
                    ))}
                </select>
                <input
                    className="input"
                    type="date"
                    value={filters.date_from === filters.date_to ? filters.date_from : ''}
                    onChange={(event) => applyFilters({ date_from: event.target.value, date_to: event.target.value })}
                    aria-label="Correspondence date"
                />
                <button type="button" className="btn btn-ghost" onClick={() => applyFilters({ q: query })}>
                    Search
                </button>
                <button
                    type="button"
                    className="btn btn-ghost"
                    onClick={() => {
                        setQuery('');
                        applyFilters({
                            q: '',
                            status: '',
                            priority: '',
                            department_id: '',
                            assigned_to_user_id: '',
                            financial_year: '',
                            date_from: '',
                            date_to: '',
                        });
                    }}
                >
                    Clear
                </button>
            </div>

            <div className="card mail-table-card">
                <div className="table-scroll">
                    <table className="tbl mail-table">
                        <thead>
                            <tr>
                                <th>Register No.</th>
                                <th>{direction === 'incoming' ? 'From' : 'Prepared by'}</th>
                                <th>Subject</th>
                                <th>{direction === 'incoming' ? 'Received' : 'Last activity'}</th>
                                <th>{direction === 'incoming' ? 'Addressed to' : 'Sent to'}</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {mails.data.map((mail) => (
                                <tr
                                    key={mail.id}
                                    className="row"
                                    tabIndex={0}
                                    onClick={() => router.get(route('mail.show', mail.id), {}, { preserveState: true, preserveScroll: true })}
                                    onKeyDown={(event) => event.key === 'Enter' && router.get(route('mail.show', mail.id))}
                                >
                                    <td className="ref">
                                        {mail.register_number}
                                        <small className="mail-cell-meta">{mail.record_kind}</small>
                                    </td>
                                    <td>{mail.sender_name}</td>
                                    <td>
                                        <strong>{mail.subject}</strong>
                                        {mail.correspondence_reference && (
                                            <small className="mail-cell-meta">Ref: {mail.correspondence_reference}</small>
                                        )}
                                    </td>
                                    <td>{direction === 'incoming' ? mail.mail_date_label : mail.activity_date_label}</td>
                                    <td>{mail.recipient_display}</td>
                                    <td>
                                        <span className={`badge ${mail.status_class}`}>{mail.status}</span>
                                        <small className="mail-cell-meta">
                                            {mail.priority} · FY {mail.financial_year ?? '—'}
                                        </small>
                                        {mail.task_reference && <small className="mail-cell-meta">{mail.task_reference}</small>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {mails.data.length === 0 && <EmptyState>No mail records match your filters.</EmptyState>}
                <Pagination meta={mails.meta} only={['filters', 'mails']} />
            </div>

            {showCapture && <CaptureMailModal direction={direction} onClose={() => setShowCapture(false)} />}
            {selectedMail && (
                <MailDetailPanel
                    mail={selectedMail}
                    props={props}
                    onClose={() => {
                        // Never navigate somewhere the viewer is not authorised
                        // to be: registry users return to the register, anyone
                        // else goes back to the linked assignment or home.
                        if (props.canViewRegister) {
                            router.get(route(indexRoute), {}, { preserveState: true, preserveScroll: true });
                        } else {
                            router.visit(selectedMail.task_url ?? '/home');
                        }
                    }}
                />
            )}
        </AppShell>
    );
}

function CaptureMailModal({ direction, onClose }: { direction: 'incoming' | 'outgoing'; onClose: () => void }) {
    const today = todayForDateInput();
    const form = useForm({
        register_number: '',
        sender_name: '',
        sender_organisation: '',
        recipient_name: '',
        subject: '',
        details: '',
        correspondence_reference: '',
        letter_date: '',
        received_date: direction === 'incoming' ? today : '',
        sent_date: direction === 'outgoing' ? today : '',
        receipt_method: direction === 'incoming' ? 'hand' : '',
        confidentiality: 'normal',
        priority: 'medium',
        status: direction === 'incoming' ? 'registered' : 'draft',
        registry_file_number: '',
        attachments: [] as File[],
        duplicate_override: false as boolean,
        duplicate_reason: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route(direction === 'incoming' ? 'mail.incoming.store' : 'mail.outgoing.store'), {
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal
            title={`Record ${direction} correspondence`}
            size="wide"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" form="mail-capture-form" className="btn btn-primary" disabled={form.processing}>
                        Save correspondence
                    </button>
                </>
            }
        >
            <form id="mail-capture-form" onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <Field label="Internal register number" hint="Leave blank to generate the next number automatically.">
                        <input
                            className="input"
                            placeholder={direction === 'incoming' ? 'IM-YYYY-NNNNN' : 'OM-YYYY-NNNNN'}
                            value={form.data.register_number}
                            onChange={(e) => form.setData('register_number', e.target.value)}
                        />
                    </Field>
                    <Field label={direction === 'incoming' ? 'From' : 'Prepared by'} required>
                        <input className="input" value={form.data.sender_name} onChange={(e) => form.setData('sender_name', e.target.value)} />
                    </Field>
                    <Field label="Organisation / office">
                        <input
                            className="input"
                            value={form.data.sender_organisation}
                            onChange={(e) => form.setData('sender_organisation', e.target.value)}
                        />
                    </Field>
                    <Field label={direction === 'incoming' ? 'Addressed to' : 'Sent to'} required>
                        <input
                            className="input"
                            placeholder={direction === 'incoming' ? 'cc PS/ES' : 'Enter recipient'}
                            value={form.data.recipient_name}
                            onChange={(e) => form.setData('recipient_name', e.target.value)}
                        />
                    </Field>
                    <Field label="Correspondence reference">
                        <input
                            className="input"
                            value={form.data.correspondence_reference}
                            onChange={(e) => form.setData('correspondence_reference', e.target.value)}
                        />
                    </Field>
                    <Field label="Subject" required wide>
                        <input className="input" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                    </Field>
                    <Field label="Details" wide hint="Provide the full context, request, background, or response contained in the mail.">
                        <textarea className="textarea" rows={5} value={form.data.details} onChange={(e) => form.setData('details', e.target.value)} />
                    </Field>
                    <Field label="Letter date">
                        <input
                            className="input"
                            type="date"
                            value={form.data.letter_date}
                            onChange={(e) => form.setData('letter_date', e.target.value)}
                        />
                    </Field>
                    <Field label={direction === 'incoming' ? 'Date received' : 'Date sent'} required={direction === 'incoming'}>
                        <input
                            className="input"
                            type="date"
                            value={direction === 'incoming' ? form.data.received_date : form.data.sent_date}
                            onChange={(e) =>
                                direction === 'incoming' ? form.setData('received_date', e.target.value) : form.setData('sent_date', e.target.value)
                            }
                        />
                    </Field>
                    {direction === 'incoming' && (
                        <Field label="Receipt method">
                            <select
                                className="select"
                                value={form.data.receipt_method}
                                onChange={(e) => form.setData('receipt_method', e.target.value)}
                            >
                                <option value="hand">Hand delivery</option>
                                <option value="courier">Courier</option>
                                <option value="email">Email</option>
                                <option value="post">Post</option>
                                <option value="other">Other</option>
                            </select>
                        </Field>
                    )}
                    <Field label="Priority">
                        <select className="select" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </Field>
                    <Field label="Initial status">
                        <select className="select" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {direction === 'incoming' ? (
                                <>
                                    <option value="received">Received</option>
                                    <option value="registered">Registered</option>
                                </>
                            ) : (
                                <>
                                    <option value="draft">Draft</option>
                                    <option value="dispatched">Dispatched</option>
                                </>
                            )}
                        </select>
                    </Field>
                    <Field label="Confidentiality">
                        <select
                            className="select"
                            value={form.data.confidentiality}
                            onChange={(e) => form.setData('confidentiality', e.target.value)}
                        >
                            <option value="normal">Normal</option>
                            <option value="confidential">Confidential</option>
                            <option value="restricted">Restricted</option>
                        </select>
                    </Field>
                    <Field label="Registry file number">
                        <input
                            className="input"
                            value={form.data.registry_file_number}
                            onChange={(e) => form.setData('registry_file_number', e.target.value)}
                        />
                    </Field>
                    <Field label="Attachments" wide hint="Up to 5 PDFs, Office documents, images, or videos; 100 MB each.">
                        <input
                            className="input"
                            type="file"
                            multiple
                            accept=".pdf,.docx,.xlsx,.pptx,image/*,video/*"
                            onChange={(e) => form.setData('attachments', Array.from(e.target.files ?? []))}
                        />
                    </Field>
                </div>
                {(form.errors.duplicate_override || form.data.duplicate_override || form.errors.duplicate_reason) && (
                    <div className="mail-duplicate-warning">
                        <strong>Possible duplicate</strong>
                        <p>{form.errors.duplicate_override || 'Confirm why this should be saved as a separate mail record.'}</p>
                        <label>
                            <input
                                type="checkbox"
                                checked={form.data.duplicate_override}
                                onChange={(e) => {
                                    form.setData('duplicate_override', e.target.checked);
                                    if (!e.target.checked) form.setData('duplicate_reason', '');
                                }}
                            />{' '}
                            This is a separate record
                        </label>
                        {form.data.duplicate_override && (
                            <div className="mail-duplicate-reason">
                                <label htmlFor="duplicate-reason">Reason this is not a duplicate *</label>
                                <textarea
                                    id="duplicate-reason"
                                    className="textarea"
                                    aria-invalid={Boolean(form.errors.duplicate_reason)}
                                    placeholder="For example: revised letter with different content or a separately delivered signed copy"
                                    value={form.data.duplicate_reason}
                                    onChange={(e) => form.setData('duplicate_reason', e.target.value)}
                                />
                                {form.errors.duplicate_reason && <div className="field-error">{form.errors.duplicate_reason}</div>}
                            </div>
                        )}
                    </div>
                )}
            </form>
        </Modal>
    );
}

function Field({
    label,
    children,
    required = false,
    wide = false,
    hint,
}: {
    label: string;
    children: React.ReactNode;
    required?: boolean;
    wide?: boolean;
    hint?: string;
}) {
    return (
        <label className={`field ${wide ? 'mail-field-wide' : ''}`}>
            <span>
                {label}
                {required && ' *'}
            </span>
            {children}
            {hint && <small className="field-help">{hint}</small>}
        </label>
    );
}

type DrawerAction = 'edit' | 'recipients' | 'assign' | 'unassign' | 'update' | null;

function MailDetailPanel({ mail, props, onClose }: { mail: MailDetail; props: Props; onClose: () => void }) {
    const [preview, setPreview] = useState<MailAttachment | null>(null);
    const [action, setAction] = useState<DrawerAction>(null);
    const [recipientToRemove, setRecipientToRemove] = useState<CorrespondenceRecipientInfo | null>(null);
    const [attachmentAction, setAttachmentAction] = useState<{ attachment: MailAttachment; mode: 'replace' | 'remove' } | null>(null);
    const assignment = mail.assignment;
    const canUnassign = mail.can_unassign && assignment !== null && !assignment.is_withdrawn && assignment.active_assignees.length > 0;
    const isAwaitingForwarding = ['incoming', 'under_review'].includes(mail.lifecycle_status);

    return (
        <Slideover
            size="wide"
            onClose={onClose}
            header={
                <div className="task-view-heading forwarded-drawer-heading">
                    <div className="task-view-eyebrow">
                        <span>{mail.record_kind}</span>
                        <span>{mail.register_number}</span>
                    </div>
                    <h2>{mail.subject}</h2>
                    <div className="task-view-badges">
                        <span className={`badge ${mail.status_class}`}>{mail.status}</span>
                        <span className={`badge ${mail.priority_class}`}>{mail.priority}</span>
                        <span className="badge muted">{mail.confidentiality}</span>
                    </div>
                </div>
            }
        >
            <div className="mail-detail-actions" role="toolbar" aria-label="Correspondence actions">
                {mail.can_assign && isAwaitingForwarding && (
                    <button type="button" className="btn btn-primary" onClick={() => setAction('assign')}>
                        <Forward aria-hidden="true" /> Forward mail
                    </button>
                )}
                {mail.can_assign && !isAwaitingForwarding && (
                    <button type="button" className="btn btn-primary" onClick={() => setAction('recipients')}>
                        <UsersRound aria-hidden="true" /> Manage recipients
                    </button>
                )}
                {mail.can_participate && (
                    <button type="button" className="btn btn-ghost" onClick={() => setAction('update')}>
                        <MessageSquarePlus aria-hidden="true" /> Add note or response
                    </button>
                )}
                <a className="btn btn-ghost" href={route('mail.print', mail.id)} target="_blank" rel="noreferrer">
                    <Printer aria-hidden="true" /> Print correspondence
                </a>
                {assignment !== null && !assignment.is_withdrawn && (
                    <Link className="btn btn-ghost" href={assignment.url}>
                        <ArrowRight aria-hidden="true" /> Open assignment
                    </Link>
                )}
                {mail.can_edit && (
                    <button type="button" className="btn btn-ghost" onClick={() => setAction('edit')}>
                        <Pencil aria-hidden="true" /> {mail.record_kind === 'Outgoing / Forwarded' ? 'Edit forwarded mail' : 'Edit mail details'}
                    </button>
                )}
                {canUnassign && (
                    <button type="button" className="btn btn-ghost danger-button" onClick={() => setAction('unassign')}>
                        <UserMinus aria-hidden="true" /> Unassign
                    </button>
                )}
            </div>

            <div className="mail-detail-sections">
                <div className="forwarded-drawer-overview">
                    <MailInformationSection mail={mail} />
                    <aside className="forwarded-drawer-sidebar">
                        <MailStatusSummary mail={mail} />
                        <AttachmentsSection mail={mail} onPreview={setPreview} onManage={mail.can_edit ? setAttachmentAction : undefined} compact />
                    </aside>
                </div>
                {(mail.primary_recipients.length > 0 || mail.cc_recipients.length > 0) && (
                    <RecipientsSection mail={mail} onRemove={mail.can_assign ? setRecipientToRemove : undefined} />
                )}
                {assignment !== null && <AssignmentSection assignment={assignment} onUnassign={canUnassign ? () => setAction('unassign') : null} />}
                {(mail.source_mail || mail.forwarded_records.length > 0) && (
                    <section className="card mail-section mail-related-workflow">
                        <SectionHeading icon={<Send aria-hidden="true" />} title="Related correspondence" sub="Traceable registry workflow" />
                        <div className="mail-related-links">
                            {mail.source_mail && (
                                <Link href={mail.source_mail.url}>
                                    <Inbox aria-hidden="true" /> Original incoming · {mail.source_mail.register_number}
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            )}
                            {mail.forwarded_records.map((record) => (
                                <Link key={record.id} href={record.url}>
                                    <Send aria-hidden="true" /> Outgoing forwarding · {record.register_number}
                                    {record.task_reference ? ` · ${record.task_reference}` : ''}
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
                <ActivitySection entries={mail.activity_history} />
            </div>

            {preview && <AttachmentPreview attachment={preview} onClose={() => setPreview(null)} />}
            {action === 'edit' && <EditMailModal mail={mail} onClose={() => setAction(null)} />}
            {action === 'recipients' && (
                <ManageRecipientsModal
                    mail={mail}
                    canUnassign={canUnassign}
                    onAdd={() => setAction('assign')}
                    onRemove={(recipient) => { setAction(null); setRecipientToRemove(recipient); }}
                    onUnassign={() => setAction('unassign')}
                    onClose={() => setAction(null)}
                />
            )}
            {action === 'assign' && <AssignMailModal mail={mail} props={props} onClose={() => setAction(null)} />}
            {action === 'update' && <CorrespondenceUpdateModal mail={mail} onClose={() => setAction(null)} />}
            {action === 'unassign' && assignment !== null && (
                <UnassignMailModal mail={mail} assignment={assignment} onClose={() => setAction(null)} />
            )}
            {recipientToRemove && (
                <RemoveRecipientModal mail={mail} recipient={recipientToRemove} onClose={() => setRecipientToRemove(null)} />
            )}
            {attachmentAction && (
                <ManageAttachmentModal
                    attachment={attachmentAction.attachment}
                    mode={attachmentAction.mode}
                    onClose={() => setAttachmentAction(null)}
                />
            )}
        </Slideover>
    );
}

function MailStatusSummary({ mail }: { mail: MailDetail }) {
    return (
        <section className="card mail-section forwarded-status-summary" aria-label="Correspondence summary">
            <div>
                <span>Priority</span>
                <strong className={`badge ${mail.priority_class}`}>{mail.priority}</strong>
            </div>
            <div>
                <span>Status</span>
                <strong className={`badge ${mail.status_class}`}>{mail.status}</strong>
            </div>
            <div>
                <span>Confidentiality</span>
                <strong>{mail.confidentiality}</strong>
            </div>
            <div>
                <span>Financial year</span>
                <strong>{mail.financial_year || '—'}</strong>
            </div>
        </section>
    );
}

function RecipientsSection({ mail, onRemove }: { mail: MailDetail; onRemove?: (recipient: CorrespondenceRecipientInfo) => void }) {
    return (
        <section className="card mail-section correspondence-recipient-section">
            <SectionHeading
                icon={<UsersRound aria-hidden="true" />}
                title="Recipients"
                sub="Primary and information-only access are tracked separately"
            />
            <div className="correspondence-recipient-columns">
                <div>
                    <h4>To</h4>
                    {mail.primary_recipients.map((recipient) => (
                        <article key={recipient.id}>
                            <div>
                                <strong>{recipient.name}</strong>
                                <small>{recipient.title || 'Office / external recipient'}</small>
                            </div>
                            <span className={`badge ${recipient.purpose === 'action_required' ? 'st-assigned' : 'muted'}`}>
                                {recipient.purpose === 'action_required' ? 'Action required' : 'No action required'}
                            </span>
                            {onRemove && recipient.task_id == null && (
                                <button type="button" className="btn btn-ghost danger-button" onClick={() => onRemove(recipient)}>
                                    <UserMinus aria-hidden="true" /> Remove
                                </button>
                            )}
                        </article>
                    ))}
                </div>
                <div>
                    <h4>CC</h4>
                    {mail.cc_recipients.map((recipient) => (
                        <article key={recipient.id}>
                            <div>
                                <strong>{recipient.name}</strong>
                                <small>{recipient.title || 'For information'}</small>
                            </div>
                            <span className="badge info">CC · Information only</span>
                            {onRemove && (
                                <button type="button" className="btn btn-ghost danger-button" onClick={() => onRemove(recipient)}>
                                    <UserMinus aria-hidden="true" /> Remove
                                </button>
                            )}
                        </article>
                    ))}
                    {mail.cc_recipients.length === 0 && <p className="recipient-empty">No CC recipients.</p>}
                </div>
            </div>
        </section>
    );
}

function SectionHeading({ icon, title, sub, aside }: { icon: React.ReactNode; title: string; sub?: string; aside?: React.ReactNode }) {
    return (
        <header className="mail-section-heading">
            <span className="mail-section-icon">{icon}</span>
            <div>
                <h3>{title}</h3>
                {sub && <p>{sub}</p>}
            </div>
            {aside && <div className="mail-section-aside">{aside}</div>}
        </header>
    );
}

function InfoItem({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="mail-info-item">
            <dt>{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

function MailInformationSection({ mail }: { mail: MailDetail }) {
    const incoming = mail.direction === 'incoming';
    return (
        <section className="card mail-section">
            <SectionHeading icon={<Inbox aria-hidden="true" />} title="Mail information" sub="Registry details of this correspondence" />
            <dl className="mail-info-grid">
                <InfoItem label="Register number">{mail.register_number}</InfoItem>
                <InfoItem label="Sender reference">{mail.correspondence_reference || 'Not provided'}</InfoItem>
                <InfoItem label={incoming ? 'From' : 'Prepared by'}>
                    {mail.sender_name}
                    {mail.sender_organisation ? ` · ${mail.sender_organisation}` : ''}
                </InfoItem>
                <InfoItem label={incoming ? 'Addressed to' : 'Sent to'}>{mail.recipient_name}</InfoItem>
                <InfoItem label="Mail type">
                    {mail.record_kind}
                    {mail.receipt_method ? ` · ${mail.receipt_method}` : ''}
                </InfoItem>
                <InfoItem label={incoming ? 'Date received' : 'Date sent'}>{mail.mail_date_label}</InfoItem>
                <InfoItem label="Letter date">{mail.letter_date_label}</InfoItem>
                <InfoItem label="Office / department">
                    {mail.office_name}
                    {mail.department_name && mail.department_name !== mail.office_name ? ` · ${mail.department_name}` : ''}
                </InfoItem>
                <InfoItem label="Priority">
                    <span className={`badge ${mail.priority_class}`}>{mail.priority}</span>
                </InfoItem>
                <InfoItem label="Status">
                    <span className={`badge ${mail.status_class}`}>{mail.status}</span>
                </InfoItem>
                <InfoItem label="Confidentiality">{mail.confidentiality}</InfoItem>
                <InfoItem label="Financial year">{mail.financial_year ?? '—'}</InfoItem>
                {mail.registry_file_number && <InfoItem label="Registry file number">{mail.registry_file_number}</InfoItem>}
                {mail.prepared_on_behalf_of && <InfoItem label="Prepared on behalf of">{mail.prepared_on_behalf_of}</InfoItem>}
                {mail.dispatch_reference && (
                    <InfoItem label="Dispatch">{`${mail.dispatch_method ?? 'Recorded'} · ${mail.dispatch_reference}`}</InfoItem>
                )}
                <InfoItem label="Captured by">{`${mail.captured_by} · ${mail.captured_at_label}`}</InfoItem>
                <InfoItem label="Last processed by">{mail.last_processed_by || mail.captured_by}</InfoItem>
            </dl>
            <div className="mail-details-text">
                <span>Details</span>
                <p>{mail.details || 'No additional details were provided.'}</p>
            </div>
        </section>
    );
}

function AssignmentSection({ assignment, onUnassign }: { assignment: AssignmentInfo; onUnassign: (() => void) | null }) {
    const latestWithdrawal = assignment.unassignments[0] ?? null;
    const officerNames =
        assignment.active_assignees.length > 0
            ? assignment.active_assignees.map((assignee) => assignee.name).join(', ')
            : assignment.assigned_officer;
    return (
        <section className="card mail-section">
            <SectionHeading
                icon={<UsersRound aria-hidden="true" />}
                title="Assignment information"
                sub={assignment.reference}
                aside={<span className={`badge ${assignment.status_class}`}>{assignment.status}</span>}
            />
            {assignment.is_withdrawn && latestWithdrawal && (
                <div className="mail-withdrawn-note" role="status">
                    <UserMinus aria-hidden="true" />
                    <div>
                        <strong>Assignment withdrawn</strong>
                        <span>
                            Unassigned from {latestWithdrawal.officer} by {latestWithdrawal.unassigned_by} on {latestWithdrawal.unassigned_at_label}.
                            Reason: {latestWithdrawal.reason}. The correspondence is back in the register and can be forwarded to another officer.
                        </span>
                    </div>
                </div>
            )}
            <dl className="mail-info-grid">
                <InfoItem label="Assigned officer">{officerNames}</InfoItem>
                <InfoItem label="Assigning officer">{assignment.assigned_by}</InfoItem>
                <InfoItem label="Assignment date">{assignment.assigned_at_label ?? '—'}</InfoItem>
                <InfoItem label="Due date">
                    {assignment.due_date_label ?? 'Not set'}
                    {assignment.is_overdue && !assignment.is_withdrawn && <span className="badge pr-urgent">Overdue</span>}
                </InfoItem>
                <InfoItem label="Progress status">{assignment.execution_status}</InfoItem>
                <InfoItem label="Progress">
                    <span className="mail-progress">
                        <ProgressBar percent={assignment.progress_percent} variant={assignment.progress_percent >= 100 ? 'done' : ''} />
                        <small>{assignment.progress_percent}%</small>
                    </span>
                </InfoItem>
                {assignment.completed_at_label && <InfoItem label="Completed on">{assignment.completed_at_label}</InfoItem>}
            </dl>
            {assignment.instructions && (
                <div className="mail-details-text">
                    <span>Instructions / annotation</span>
                    <p>{assignment.instructions}</p>
                </div>
            )}
            {assignment.unassignments.length > 0 && (
                <div className="mail-unassignment-history">
                    <span>Withdrawal history</span>
                    {assignment.unassignments.map((record) => (
                        <article key={record.id}>
                            <strong>{record.officer}</strong>
                            <small>
                                Assigned {record.originally_assigned_at_label} · withdrawn {record.unassigned_at_label} by {record.unassigned_by}
                            </small>
                            <p>{record.reason}</p>
                        </article>
                    ))}
                </div>
            )}
            <footer className="mail-assignment-actions">
                <Link className="btn btn-ghost" href={assignment.url}>
                    <ArrowRight aria-hidden="true" /> Open full assignment
                </Link>
                {onUnassign && (
                    <button type="button" className="btn btn-ghost danger-button" onClick={onUnassign}>
                        <UserMinus aria-hidden="true" /> Unassign…
                    </button>
                )}
            </footer>
        </section>
    );
}

function AttachmentsSection({
    mail,
    onPreview,
    onManage,
    compact = false,
}: {
    mail: MailDetail;
    onPreview: (file: MailAttachment) => void;
    onManage?: (action: { attachment: MailAttachment; mode: 'replace' | 'remove' }) => void;
    compact?: boolean;
}) {
    return (
        <section className={`card mail-section ${compact ? 'mail-attachments-compact' : ''}`}>
            <SectionHeading
                icon={<Paperclip aria-hidden="true" />}
                title="Attachments"
                sub={mail.attachments_linked_from_source ? 'Linked from the original incoming correspondence' : 'Source documents'}
                aside={<span className="badge">{mail.attachments.length}</span>}
            />
            {mail.attachments.length === 0 ? (
                <EmptyState>No files were attached.</EmptyState>
            ) : (
                <div className="mail-attachment-list">
                    {mail.attachments.map((file) => (
                        <article key={file.id}>
                            <span>
                                <FileText aria-hidden="true" />
                            </span>
                            <div>
                                <strong>{file.filename}</strong>
                                <small>
                                    {file.size_label} · {file.uploaded_by}
                                    {file.uploaded_at_label ? ` · ${file.uploaded_at_label}` : ''}
                                    {file.correspondence_attachment_id ? ` · Version ${file.version_number}` : ''}
                                </small>
                            </div>
                            <div>
                                {file.preview_url && (
                                    <button type="button" className="btn btn-ghost" onClick={() => onPreview(file)}>
                                        <Eye aria-hidden="true" /> Preview
                                    </button>
                                )}
                                {file.preview_url && (
                                    <a className="btn btn-ghost" href={file.preview_url} target="_blank" rel="noreferrer">
                                        <ExternalLink aria-hidden="true" /> Open
                                    </a>
                                )}
                                <a className="btn btn-ghost" href={file.download_url}>
                                    <Download aria-hidden="true" /> Download
                                </a>
                                {onManage && file.correspondence_attachment_id && (
                                    <button type="button" className="btn btn-ghost" onClick={() => onManage({ attachment: file, mode: 'replace' })}>
                                        <Pencil aria-hidden="true" /> Replace
                                    </button>
                                )}
                                {onManage && file.correspondence_attachment_id && (
                                    <button type="button" className="btn btn-ghost danger-button" onClick={() => onManage({ attachment: file, mode: 'remove' })}>
                                        <Trash2 aria-hidden="true" /> Remove
                                    </button>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
}

function ActivitySection({ entries }: { entries: ActivityEntry[] }) {
    return (
        <section className="card mail-section mail-history-card">
            <SectionHeading
                icon={<History aria-hidden="true" />}
                title="Correspondence history"
                sub="Forwarding, recipients, tasks, notes, files and accountability events in one permanent record"
                aside={<span className="badge">{entries.length}</span>}
            />
            {entries.length === 0 ? (
                <EmptyState>No activity has been recorded.</EmptyState>
            ) : (
                <div className="mail-history-list">
                    {entries.map((entry) => (
                        <article key={entry.id}>
                            <div className="mail-history-meta">
                                <span className={entry.source === 'Assignment' ? 'assignment-event' : entry.source === 'Correspondence' ? 'correspondence-event' : ''}>
                                    {entry.source === 'Assignment' ? (
                                        <UsersRound aria-hidden="true" />
                                    ) : entry.source === 'Correspondence' ? (
                                        <MessageSquarePlus aria-hidden="true" />
                                    ) : (
                                        <History aria-hidden="true" />
                                    )}
                                </span>
                                <div>
                                    <strong>{entry.action}</strong>
                                    <small>
                                        {entry.performed_by} · {entry.performed_at_label} · {entry.source}
                                    </small>
                                </div>
                            </div>
                            {entry.note && <p className="mail-history-note">{entry.note}</p>}
                            {entry.recipients && entry.recipients.length > 0 && (
                                <div className="timeline-recipient-list">
                                    {entry.recipients.map((recipient, index) => (
                                        <span key={`${recipient.type}-${recipient.name}-${index}`} className={`badge ${recipient.type === 'cc' ? 'info' : 'st-assigned'}`}>
                                            {recipient.type === 'cc' ? 'CC' : 'To'} · {recipient.name}
                                        </span>
                                    ))}
                                </div>
                            )}
                            {entry.attachments && entry.attachments.length > 0 && (
                                <div className="timeline-attachment-list">
                                    {entry.attachments.map((attachment) => (
                                        <a key={attachment.download_url} href={attachment.download_url}>
                                            <Paperclip aria-hidden="true" /> {attachment.filename}
                                        </a>
                                    ))}
                                </div>
                            )}
                            {entry.changes.length > 0 && (
                                <div className="mail-history-changes">
                                    {entry.changes.map((change) => (
                                        <div key={change.field}>
                                            <strong>{change.field}</strong>
                                            <p>
                                                <span>{change.before}</span>
                                                <ArrowRight aria-hidden="true" />
                                                <span>{change.after}</span>
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
}

function UnassignMailModal({ mail, assignment, onClose }: { mail: MailDetail; assignment: AssignmentInfo; onClose: () => void }) {
    const form = useForm({
        user_ids: assignment.active_assignees.map((assignee) => assignee.user_id),
        reason: '',
        comments: '',
        confirmed: false as boolean,
    });

    const toggleUser = (userId: number, checked: boolean) => {
        form.setData('user_ids', checked ? [...new Set([...form.data.user_ids, userId])] : form.data.user_ids.filter((id) => id !== userId));
    };

    return (
        <Modal
            title={`Withdraw assignment ${assignment.reference}`}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-danger"
                        disabled={form.processing || form.data.user_ids.length === 0 || !form.data.reason.trim() || !form.data.confirmed}
                        onClick={() =>
                            form.post(route('tasks.workflow.unassign', assignment.task_id), {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        <UserMinus aria-hidden="true" /> Confirm unassignment
                    </button>
                </>
            }
        >
            <div className="forward-source-summary">
                <div>
                    <span>Correspondence</span>
                    <strong>
                        {mail.register_number} · {mail.subject}
                    </strong>
                </div>
                <div>
                    <span>Currently assigned to</span>
                    <strong>{assignment.active_assignees.map((assignee) => assignee.name).join(', ') || assignment.assigned_officer}</strong>
                </div>
            </div>
            <div className="unassignment-warning" role="status">
                <UserMinus aria-hidden="true" />
                <div>
                    <strong>The assignment record will not be deleted.</strong>
                    <span>
                        The selected officer(s) immediately lose active access and all deadline, overdue, and performance tracking stops. The
                        correspondence, the assignment, and its full history remain in the permanent audit record, and the mail returns to the
                        register for reassignment.
                    </span>
                </div>
            </div>
            <FormErrorSummary errors={form.errors} />
            <fieldset className="unassignment-user-list">
                <legend>Officers to unassign *</legend>
                {assignment.active_assignees.map((assignee) => (
                    <label key={assignee.user_id} className="unassignment-option">
                        <input
                            type="checkbox"
                            checked={form.data.user_ids.includes(assignee.user_id)}
                            onChange={(event) => toggleUser(assignee.user_id, event.target.checked)}
                        />
                        <span>
                            <strong>{assignee.name}</strong>
                            <small>
                                {assignee.title || 'Officer'}
                                {assignee.assigned_at_label ? ` · assigned ${assignee.assigned_at_label}` : ''}
                            </small>
                        </span>
                    </label>
                ))}
            </fieldset>
            <div className="field">
                <label htmlFor="mail-unassign-reason">Reason for unassignment *</label>
                <textarea
                    id="mail-unassign-reason"
                    required
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    placeholder="State why this assignment is being withdrawn. The reason is stored in the audit trail."
                />
                {form.errors.reason && <div className="field-error">{form.errors.reason}</div>}
            </div>
            <div className="field">
                <label htmlFor="mail-unassign-comments">Additional comments</label>
                <textarea
                    id="mail-unassign-comments"
                    value={form.data.comments}
                    onChange={(event) => form.setData('comments', event.target.value)}
                    placeholder="Optional context for the audit trail and notification."
                />
            </div>
            <label className="unassignment-confirmation">
                <input type="checkbox" checked={form.data.confirmed} onChange={(event) => form.setData('confirmed', event.target.checked)} />
                <span>I confirm this assignment should be removed from active tracking.</span>
            </label>
        </Modal>
    );
}

function EditMailModal({ mail, onClose }: { mail: MailDetail; onClose: () => void }) {
    const form = useForm({ ...mail.edit_values });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('mail.update', mail.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            title={`Edit ${mail.register_number}`}
            size="wide"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" form="mail-edit-form" className="btn btn-primary" disabled={form.processing || !form.isDirty}>
                        Save changes
                    </button>
                </>
            }
        >
            <div className="mail-edit-notice">
                <History />
                <p>
                    <strong>Every change is recorded.</strong>
                    <span>The system keeps the previous and new values, together with your name and the date and time.</span>
                </p>
            </div>
            <form id="mail-edit-form" onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <Field label="From" required>
                        <input className="input" value={form.data.sender_name} onChange={(e) => form.setData('sender_name', e.target.value)} />
                    </Field>
                    <Field label="Organisation / office">
                        <input
                            className="input"
                            value={form.data.sender_organisation}
                            onChange={(e) => form.setData('sender_organisation', e.target.value)}
                        />
                    </Field>
                    <Field label="Addressed to" required>
                        <input className="input" value={form.data.recipient_name} onChange={(e) => form.setData('recipient_name', e.target.value)} />
                    </Field>
                    <Field label="Correspondence reference">
                        <input
                            className="input"
                            value={form.data.correspondence_reference}
                            onChange={(e) => form.setData('correspondence_reference', e.target.value)}
                        />
                    </Field>
                    <Field label="Subject" required wide>
                        <input className="input" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                    </Field>
                    <Field label="Details" wide>
                        <textarea className="textarea" rows={5} value={form.data.details} onChange={(e) => form.setData('details', e.target.value)} />
                    </Field>
                    <Field label="Letter date">
                        <input
                            className="input"
                            type="date"
                            value={form.data.letter_date}
                            onChange={(e) => form.setData('letter_date', e.target.value)}
                        />
                    </Field>
                    <Field label={mail.direction === 'incoming' ? 'Date received' : 'Date sent'} required={mail.direction === 'incoming'}>
                        <input
                            className="input"
                            type="date"
                            value={mail.direction === 'incoming' ? form.data.received_date : form.data.sent_date}
                            onChange={(e) =>
                                mail.direction === 'incoming'
                                    ? form.setData('received_date', e.target.value)
                                    : form.setData('sent_date', e.target.value)
                            }
                        />
                    </Field>
                    {mail.direction === 'incoming' && (
                        <Field label="Receipt method">
                            <select
                                className="select"
                                value={form.data.receipt_method}
                                onChange={(e) => form.setData('receipt_method', e.target.value)}
                            >
                                <option value="">Not specified</option>
                                <option value="hand">Hand delivery</option>
                                <option value="courier">Courier</option>
                                <option value="email">Email</option>
                                <option value="post">Post</option>
                                <option value="other">Other</option>
                            </select>
                        </Field>
                    )}
                    <Field label="Priority">
                        <select className="select" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </Field>
                    <Field label="Confidentiality">
                        <select
                            className="select"
                            value={form.data.confidentiality}
                            onChange={(e) => form.setData('confidentiality', e.target.value)}
                        >
                            <option value="normal">Normal</option>
                            <option value="confidential">Confidential</option>
                            <option value="restricted">Restricted</option>
                        </select>
                    </Field>
                    <Field label="Registry file number">
                        <input
                            className="input"
                            value={form.data.registry_file_number}
                            onChange={(e) => form.setData('registry_file_number', e.target.value)}
                        />
                    </Field>
                </div>
            </form>
        </Modal>
    );
}

function ManageRecipientsModal({
    mail,
    canUnassign,
    onAdd,
    onRemove,
    onUnassign,
    onClose,
}: {
    mail: MailDetail;
    canUnassign: boolean;
    onAdd: () => void;
    onRemove: (recipient: CorrespondenceRecipientInfo) => void;
    onUnassign: () => void;
    onClose: () => void;
}) {
    const recipients = [
        ...mail.primary_recipients.map((recipient) => ({ ...recipient, recipientType: 'To' })),
        ...mail.cc_recipients.map((recipient) => ({ ...recipient, recipientType: 'CC' })),
    ];

    return (
        <Modal
            title="Manage correspondence recipients"
            size="wide"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>Close</button>
                    <button type="button" className="btn btn-primary" onClick={onAdd}>
                        <Plus aria-hidden="true" /> Add recipients
                    </button>
                </>
            }
        >
            <div className="recipient-management-intro">
                <UsersRound aria-hidden="true" />
                <div>
                    <strong>Edit forwarded mail recipients</strong>
                    <p>Add To or CC recipients at any time. To change an action owner, withdraw the current assignment, then add the replacement recipient. Every change remains in Correspondence history.</p>
                </div>
            </div>
            <div className="recipient-management-list">
                {recipients.length === 0 && <EmptyState>No active recipients. Add a recipient to forward this correspondence again.</EmptyState>}
                {recipients.map((recipient) => {
                    const hasActiveTask = recipient.purpose === 'action_required' && recipient.task_id != null;
                    return (
                        <article key={`${recipient.recipientType}-${recipient.id}`}>
                            <span className={`recipient-type-mark ${recipient.recipientType === 'CC' ? 'cc' : ''}`}>{recipient.recipientType}</span>
                            <div>
                                <strong>{recipient.name}</strong>
                                <small>{recipient.title || 'External or office recipient'} · {recipient.purpose === 'action_required' ? 'Action required' : 'Information only'}</small>
                            </div>
                            {hasActiveTask ? (
                                canUnassign ? (
                                    <button type="button" className="btn btn-ghost danger-button" onClick={onUnassign}>
                                        <UserMinus aria-hidden="true" /> Withdraw assignment
                                    </button>
                                ) : (
                                    <span className="badge muted">Managed through assignment</span>
                                )
                            ) : (
                                <button type="button" className="btn btn-ghost danger-button" onClick={() => onRemove(recipient)}>
                                    <UserMinus aria-hidden="true" /> Remove
                                </button>
                            )}
                        </article>
                    );
                })}
            </div>
        </Modal>
    );
}

function RemoveRecipientModal({
    mail,
    recipient,
    onClose,
}: {
    mail: MailDetail;
    recipient: CorrespondenceRecipientInfo;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.delete(route('mail.recipients.destroy', [mail.id, recipient.id]), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            title="Remove correspondence recipient"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
                    <button type="submit" form="remove-correspondence-recipient" className="btn btn-danger" disabled={form.processing || form.data.reason.trim().length < 5}>
                        <UserMinus aria-hidden="true" /> {form.processing ? 'Removing…' : 'Remove recipient'}
                    </button>
                </>
            }
        >
            <form id="remove-correspondence-recipient" onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                <p className="mail-modal-hint">
                    Remove <strong>{recipient.name}</strong> from active correspondence access? Their original recipient entry remains in the audit history.
                </p>
                <Field label="Reason for removal" hint="Required for the immutable Correspondence history.">
                    <textarea
                        className="textarea"
                        rows={4}
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        placeholder="Explain why this recipient is being removed"
                        autoFocus
                    />
                </Field>
            </form>
        </Modal>
    );
}

function CorrespondenceUpdateModal({ mail, onClose }: { mail: MailDetail; onClose: () => void }) {
    const form = useForm({
        type: 'note',
        body: '',
        attachments: [] as File[],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('mail.updates.store', mail.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            title="Add note or response"
            size="wide"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" form="correspondence-update-form" className="btn btn-primary" disabled={form.processing || !form.data.body.trim()}>
                        <MessageSquarePlus aria-hidden="true" /> {form.processing ? 'Saving…' : 'Save to history'}
                    </button>
                </>
            }
        >
            <form id="correspondence-update-form" onSubmit={submit} encType="multipart/form-data">
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <Field label="Entry type" required>
                        <select className="select" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                            <option value="note">Note</option>
                            <option value="annotation">Annotation</option>
                            <option value="progress">Progress note</option>
                            <option value="response">Response</option>
                            <option value="clarification">Request for clarification</option>
                            <option value="recommendation">Recommendation</option>
                            <option value="decision">Decision</option>
                        </select>
                    </Field>
                    <Field label="Note or response" required wide hint="This entry becomes part of the permanent Correspondence history.">
                        <textarea
                            className="textarea"
                            rows={7}
                            value={form.data.body}
                            onChange={(event) => form.setData('body', event.target.value)}
                            placeholder="Write the note, response, recommendation, or decision…"
                        />
                    </Field>
                    <Field label="Supporting documents" wide>
                        <label className="assignment-file-picker">
                            <Paperclip aria-hidden="true" />
                            <span>
                                {form.data.attachments.length > 0
                                    ? `${form.data.attachments.length} file(s) selected`
                                    : 'Choose optional files'}
                            </span>
                            <input type="file" multiple onChange={(event) => form.setData('attachments', Array.from(event.target.files ?? []))} />
                        </label>
                    </Field>
                </div>
            </form>
        </Modal>
    );
}

function AssignMailModal({ mail, props, onClose }: { mail: MailDetail; props: Props; onClose: () => void }) {
    const today = todayForDateInput();
    const form = useForm({
        target_type: 'individual' as 'individual' | 'multiple' | 'office' | 'department',
        department_id: '',
        organizational_unit_id: '',
        target_department_id: '',
        assigned_to_user_ids: [] as number[],
        cc_user_ids: [] as number[],
        external_recipients: [] as Array<{ name: string; organisation: string; recipient_type: 'to' | 'cc' }>,
        action_required: true as boolean,
        forwarded_date: today,
        priority: 'medium',
        due_date: '',
        instructions: '',
        workstream_id: '',
        attachments: [] as File[],
    });
    const [recipients, setRecipients] = useState<RecipientSuggestion[]>([]);
    const [ccRecipients, setCcRecipients] = useState<RecipientSuggestion[]>([]);
    const [externalName, setExternalName] = useState('');
    const [externalOrganisation, setExternalOrganisation] = useState('');
    const [externalType, setExternalType] = useState<'to' | 'cc'>('to');
    const [externalExpanded, setExternalExpanded] = useState(false);
    const addExternalRecipient = () => {
        const name = externalName.trim();
        if (!name) return;
        form.setData('external_recipients', [
            ...form.data.external_recipients,
            { name, organisation: externalOrganisation.trim(), recipient_type: externalType },
        ]);
        setExternalName('');
        setExternalOrganisation('');
    };
    const removeExternalRecipient = (index: number) => {
        form.setData('external_recipients', form.data.external_recipients.filter((_, current) => current !== index));
    };
    const selectRecipient = (next: RecipientSuggestion | null) => {
        if (next === null || recipients.some((recipient) => recipient.key === next.key) || ccRecipients.some((recipient) => recipient.key === next.key)) {
            return;
        }
        const isGroup = next.assignment_target_type !== 'individual';
        const selected = isGroup
            ? [next]
            : [...(recipients.some((recipient) => recipient.assignment_target_type !== 'individual') ? [] : recipients), next];
        setRecipients(selected);
        const individuals = selected.filter((recipient) => recipient.assignment_target_type === 'individual');
        form.setData((current) => ({
            ...current,
            target_type: isGroup ? next.assignment_target_type : individuals.length > 1 ? 'multiple' : 'individual',
            assigned_to_user_ids: individuals.map((recipient) => recipient.id),
            organizational_unit_id: next.assignment_target_type === 'office' ? String(next.id) : '',
            target_department_id: next.assignment_target_type === 'department' ? String(next.id) : '',
            department_id: next.department_id === null || next.department_id === undefined ? '' : String(next.department_id),
        }));
        form.clearErrors('assigned_to_user_ids', 'department_id', 'organizational_unit_id', 'target_department_id', 'target_type');
    };
    const selectCcRecipient = (next: RecipientSuggestion | null) => {
        if (
            next === null ||
            next.assignment_target_type !== 'individual' ||
            ccRecipients.some((recipient) => recipient.key === next.key) ||
            recipients.some((recipient) => recipient.key === next.key)
        ) {
            return;
        }
        const selected = [...ccRecipients, next];
        setCcRecipients(selected);
        form.setData('cc_user_ids', selected.map((recipient) => recipient.id));
        form.clearErrors('cc_user_ids');
    };
    const removeCcRecipient = (key: string) => {
        const selected = ccRecipients.filter((recipient) => recipient.key !== key);
        setCcRecipients(selected);
        form.setData('cc_user_ids', selected.map((recipient) => recipient.id));
    };
    const removeRecipient = (key: string) => {
        const selected = recipients.filter((recipient) => recipient.key !== key);
        const individuals = selected.filter((recipient) => recipient.assignment_target_type === 'individual');
        setRecipients(selected);
        form.setData((current) => ({
            ...current,
            target_type: individuals.length > 1 ? 'multiple' : 'individual',
            assigned_to_user_ids: individuals.map((recipient) => recipient.id),
            organizational_unit_id: '',
            target_department_id: '',
            department_id: selected[0]?.department_id ? String(selected[0].department_id) : '',
        }));
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('mail.assign', mail.id), {
            forceFormData: true,
            preserveScroll: true,
        });
    };
    return (
        <Modal
            title="Forward correspondence"
            size="wide"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="mail-assign-form"
                        className="btn btn-primary"
                        disabled={
                            form.processing ||
                            (recipients.length === 0 && !form.data.external_recipients.some((recipient) => recipient.recipient_type === 'to')) ||
                            (form.data.action_required && recipients.length === 0)
                        }
                    >
                        {form.processing ? (
                            <>
                                <LoaderCircle className="spin" aria-hidden="true" /> Forwarding securely…
                            </>
                        ) : (
                            <>
                                <Forward aria-hidden="true" /> Forward correspondence
                            </>
                        )}
                    </button>
                </>
            }
        >
            <p className="mail-modal-hint">
                Forwarding moves this item from Active Incoming to Outgoing / Forwarded. Its original receipt details remain in the timeline.
            </p>
            <div className="forward-source-summary">
                <div>
                    <span>Sender</span>
                    <strong>{mail.sender_name}</strong>
                </div>
                <div>
                    <span>Subject</span>
                    <strong>{mail.subject}</strong>
                </div>
                <div>
                    <span>Reference</span>
                    <strong>{mail.correspondence_reference || mail.register_number}</strong>
                </div>
                <div>
                    <span>Date received</span>
                    <strong>{mail.mail_date_label}</strong>
                </div>
                <div>
                    <span>Receiving office</span>
                    <strong>{mail.office_name}</strong>
                </div>
                <div>
                    <span>Attachments</span>
                    <strong>
                        {mail.attachments.length} linked source {mail.attachments.length === 1 ? 'file' : 'files'}
                    </strong>
                </div>
            </div>
            <form id="mail-assign-form" onSubmit={submit} encType="multipart/form-data">
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <Field label="Date forwarded" required hint="Today is selected automatically. Choose an earlier date only when recording a previous forwarding action.">
                        <input
                            className="input"
                            type="date"
                            max={today}
                            required
                            value={form.data.forwarded_date}
                            onChange={(event) => form.setData('forwarded_date', event.target.value)}
                        />
                    </Field>
                    <RecipientPicker
                        mailId={mail.id}
                        selected={null}
                        onSelect={selectRecipient}
                        label="To / Primary recipients"
                        placeholder="Search for an officer, office, or department"
                        error={
                            form.errors.assigned_to_user_ids ||
                            form.errors.department_id ||
                            form.errors.organizational_unit_id ||
                            form.errors.target_department_id ||
                            form.errors.target_type
                        }
                    />
                    {recipients.length > 0 && (
                        <div className="selected-assignees">
                            {recipients.map((recipient) => (
                                <span key={recipient.key} className="selected-assignee">
                                    <span>
                                        <strong>{recipient.name}</strong>
                                        <small>
                                            {recipient.title || recipient.department || 'Staff member'} · {recipient.role}
                                        </small>
                                    </span>
                                    <button type="button" onClick={() => removeRecipient(recipient.key)} aria-label={`Remove ${recipient.name}`}>
                                        <Trash2 aria-hidden="true" />
                                    </button>
                                </span>
                            ))}
                        </div>
                    )}
                    <RecipientPicker
                        mailId={mail.id}
                        selected={null}
                        onSelect={selectCcRecipient}
                        label="CC / For information"
                        placeholder="Search for a staff member to copy"
                        required={false}
                        allowGroups={false}
                        error={form.errors.cc_user_ids}
                    />
                    {ccRecipients.length > 0 && (
                        <div className="selected-assignees cc-recipient-list">
                            {ccRecipients.map((recipient) => (
                                <span key={recipient.key} className="selected-assignee">
                                    <span>
                                        <strong>{recipient.name}</strong>
                                        <small>{recipient.title || 'Staff member'} · CC — information only</small>
                                    </span>
                                    <button type="button" onClick={() => removeCcRecipient(recipient.key)} aria-label={`Remove ${recipient.name} from CC`}>
                                        <Trash2 aria-hidden="true" />
                                    </button>
                                </span>
                            ))}
                        </div>
                    )}
                    <fieldset className="forward-external-recipient mail-field-wide">
                        <legend>External recipient <span>(optional)</span></legend>
                        <button
                            type="button"
                            className="forward-external-toggle"
                            aria-expanded={externalExpanded}
                            aria-controls="forward-external-fields"
                            onClick={() => setExternalExpanded((current) => !current)}
                        >
                            <span>
                                <ExternalLink aria-hidden="true" />
                                <span>
                                    <strong>{form.data.external_recipients.length > 0 ? `${form.data.external_recipients.length} external recipient(s) added` : 'Add an external recipient'}</strong>
                                    <small>Open only when forwarding outside the organisation.</small>
                                </span>
                            </span>
                            <ChevronDown className={externalExpanded ? 'expanded' : ''} aria-hidden="true" />
                        </button>
                        {externalExpanded && (
                            <div id="forward-external-fields" className="forward-external-grid">
                                <label>
                                    <span>Name</span>
                                    <input className="input" value={externalName} onChange={(event) => setExternalName(event.target.value)} placeholder="Institution or contact name" />
                                </label>
                                <label>
                                    <span>Organisation</span>
                                    <input className="input" value={externalOrganisation} onChange={(event) => setExternalOrganisation(event.target.value)} placeholder="Optional organisation" />
                                </label>
                                <label>
                                    <span>Recipient type</span>
                                    <select className="select" value={externalType} onChange={(event) => setExternalType(event.target.value as 'to' | 'cc')}>
                                        <option value="to">To / Primary</option>
                                        <option value="cc">CC / Information</option>
                                    </select>
                                </label>
                                <button type="button" className="btn btn-ghost" onClick={addExternalRecipient} disabled={!externalName.trim()}>
                                    <Plus aria-hidden="true" /> Add external recipient
                                </button>
                            </div>
                        )}
                        {form.data.external_recipients.length > 0 && (
                            <div className="selected-assignees cc-recipient-list">
                                {form.data.external_recipients.map((recipient, index) => (
                                    <span key={`${recipient.name}-${index}`} className="selected-assignee">
                                        <span>
                                            <strong>{recipient.name}</strong>
                                            <small>{recipient.organisation || 'External'} · {recipient.recipient_type === 'to' ? 'Primary' : 'CC — information only'}</small>
                                        </span>
                                        <button type="button" onClick={() => removeExternalRecipient(index)} aria-label={`Remove ${recipient.name}`}>
                                            <Trash2 aria-hidden="true" />
                                        </button>
                                    </span>
                                ))}
                            </div>
                        )}
                        {form.data.action_required && recipients.length === 0 && form.data.external_recipients.some((recipient) => recipient.recipient_type === 'to') && (
                            <small className="field-help">Add an internal officer, office, or department to own an action-required task, or select Information only.</small>
                        )}
                    </fieldset>
                    <fieldset className="forward-purpose-options mail-field-wide">
                        <legend>What should the primary recipient do?</legend>
                        <label className={form.data.action_required ? 'selected' : ''}>
                            <input
                                type="radio"
                                name="forward-purpose"
                                checked={form.data.action_required}
                                onChange={() => form.setData('action_required', true)}
                            />
                            <span><strong>Action required</strong><small>Create a tracked task; a deadline remains optional.</small></span>
                        </label>
                        <label className={!form.data.action_required ? 'selected' : ''}>
                            <input
                                type="radio"
                                name="forward-purpose"
                                checked={!form.data.action_required}
                                onChange={() => form.setData((current) => ({ ...current, action_required: false, due_date: '' }))}
                            />
                            <span><strong>Information only</strong><small>Share the correspondence without creating a task or workload entry.</small></span>
                        </label>
                    </fieldset>
                    <Field label="Priority">
                        <select className="select" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                            {props.priorityOptions.map((p) => (
                                <option key={p.value} value={p.value}>
                                    {p.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    {form.data.action_required && (
                        <Field label="Due date" hint="Optional. Leave blank when no formal deadline applies.">
                            <input className="input" type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                        </Field>
                    )}
                    <Field label="Project, programme or subject">
                        <select className="select" value={form.data.workstream_id} onChange={(e) => form.setData('workstream_id', e.target.value)}>
                            <option value="">Not specified</option>
                            {props.workstreamOptions.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field
                        label="Annotation / Instructions from the Originating Officer"
                        wide
                        hint="This becomes an immutable official annotation showing the issuing officer, title, date and time."
                    >
                        <textarea
                            className="textarea"
                            rows={6}
                            value={form.data.instructions}
                            onChange={(e) => form.setData('instructions', e.target.value)}
                            placeholder="Enter the originating officer's annotation or instructions. Line breaks are preserved."
                        />
                    </Field>
                    <Field
                        label="Supporting documents"
                        wide
                        hint="Files are added to the correspondence thread. Original source documents remain linked and are not duplicated."
                    >
                        <label className="assignment-file-picker">
                            <Paperclip aria-hidden="true" />
                            <span>
                                {form.data.attachments.length > 0
                                    ? `${form.data.attachments.length} supporting file(s) selected`
                                    : 'Choose supporting files'}
                            </span>
                            <input type="file" multiple onChange={(event) => form.setData('attachments', Array.from(event.target.files ?? []))} />
                        </label>
                    </Field>
                </div>
                <div className="forward-review-panel">
                    <div>
                        <UsersRound aria-hidden="true" />
                        <span>
                            <strong>Receiving target</strong>
                            {recipients.length === 0
                                ? 'Select an officer, office or department'
                                : recipients.map((recipient) => recipient.name).join(', ')}
                        </span>
                    </div>
                    <div>
                        <ShieldCheck aria-hidden="true" />
                        <span>
                            <strong>Routing outcome</strong>
                            {form.data.action_required
                                ? 'The correspondence will move to Outgoing / Forwarded and a tracked action will be created.'
                                : 'The correspondence will move to Outgoing / Forwarded without creating a task.'}
                        </span>
                    </div>
                </div>
            </form>
        </Modal>
    );
}

function ManageAttachmentModal({
    attachment,
    mode,
    onClose,
}: {
    attachment: MailAttachment;
    mode: 'replace' | 'remove';
    onClose: () => void;
}) {
    const form = useForm({ replacement: null as File | null, reason: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!attachment.correspondence_attachment_id) return;
        if (mode === 'replace') {
            form.post(route('correspondence.attachments.replace', attachment.correspondence_attachment_id), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: onClose,
            });
            return;
        }
        form.delete(route('correspondence.attachments.destroy', attachment.correspondence_attachment_id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            title={mode === 'replace' ? 'Replace attachment' : 'Remove attachment'}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
                    <button
                        type="submit"
                        form="manage-correspondence-attachment"
                        className={mode === 'remove' ? 'btn btn-danger' : 'btn btn-primary'}
                        disabled={form.processing || form.data.reason.trim().length < 5 || (mode === 'replace' && !form.data.replacement)}
                    >
                        {mode === 'replace' ? <Pencil aria-hidden="true" /> : <Trash2 aria-hidden="true" />}
                        {form.processing ? 'Saving history…' : mode === 'replace' ? 'Replace attachment' : 'Remove attachment'}
                    </button>
                </>
            }
        >
            <form id="manage-correspondence-attachment" onSubmit={submit} encType="multipart/form-data">
                <FormErrorSummary errors={form.errors} />
                <p className="mail-modal-hint">
                    <strong>{attachment.filename}</strong> is version {attachment.version_number}. The current file and its metadata will remain traceable after this action.
                </p>
                {mode === 'replace' && (
                    <Field label="Replacement file">
                        <label className="assignment-file-picker">
                            <Paperclip aria-hidden="true" />
                            <span>{form.data.replacement?.name || 'Choose replacement file'}</span>
                            <input type="file" onChange={(event) => form.setData('replacement', event.target.files?.[0] ?? null)} />
                        </label>
                    </Field>
                )}
                <Field label={mode === 'replace' ? 'Reason for replacement' : 'Reason for removal'} hint="Required for the immutable audit trail.">
                    <textarea
                        className="textarea"
                        rows={4}
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        placeholder="Explain why this file is being changed"
                    />
                </Field>
            </form>
        </Modal>
    );
}

function AttachmentPreview({ attachment, onClose }: { attachment: MailAttachment; onClose: () => void }) {
    return (
        <Modal
            title={attachment.filename}
            size="wide"
            onClose={onClose}
            footer={
                <a className="btn btn-primary" href={attachment.download_url}>
                    <Download /> Download
                </a>
            }
        >
            <div className="evidence-preview">
                {attachment.preview_kind === 'image' && <img src={attachment.preview_url ?? ''} alt={attachment.filename} />}
                {attachment.preview_kind === 'video' && <video src={attachment.preview_url ?? ''} controls />}
                {(attachment.preview_kind === 'pdf' || attachment.preview_kind === 'document') && (
                    <iframe src={attachment.preview_url ?? ''} title={attachment.filename} />
                )}
            </div>
        </Modal>
    );
}
