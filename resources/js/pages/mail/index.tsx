import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import Pagination from '@/components/ats/pagination';
import RecipientPicker, { type RecipientSuggestion } from '@/components/ats/recipient-picker';
import Slideover from '@/components/ats/slideover';
import type { PaginatedData, SelectOption } from '@/types';
import { Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    CalendarDays,
    Download,
    Eye,
    FileText,
    History,
    Inbox,
    Pencil,
    Plus,
    Send,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface MailRow {
    id: number;
    direction: 'incoming' | 'outgoing';
    register_number: string;
    sender_name: string;
    recipient_name: string;
    subject: string;
    correspondence_reference: string | null;
    mail_date_label: string;
    status: string;
    status_value: string;
    status_class: string;
    priority: string;
    priority_class: string;
    financial_year: string | null;
    department_name: string | null;
    task_reference: string | null;
}

interface MailAttachment {
    id: number;
    filename: string;
    mime_type: string;
    size_label: string;
    preview_kind: 'pdf' | 'image' | 'video' | 'document' | 'none';
    preview_url: string | null;
    download_url: string;
    uploaded_by: string;
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
    activity_history: {
        id: number;
        action: string;
        performed_by: string;
        performed_at_label: string;
        changes: { field: string; before: string; after: string }[];
    }[];
    can_assign: boolean;
    can_edit: boolean;
    can_approve: boolean;
}

interface Props {
    direction: 'incoming' | 'outgoing';
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
    const title = direction === 'incoming' ? 'Incoming Correspondence' : 'Outgoing Correspondence';
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
                    <span className="result-eyebrow">Office of the Permanent Secretary</span>
                    <h1>{title}</h1>
                    <div className="page-sub">A shared office register for receiving, preparing, reviewing, forwarding, dispatching, and archiving correspondence.</div>
                </div>
                {props.canManageRegister && (
                    <button type="button" className="btn btn-primary" onClick={() => setShowCapture(true)}>
                        <Plus aria-hidden="true" /> Record {direction} correspondence
                    </button>
                )}
            </div>

            <nav className="mail-register-tabs" aria-label="Mail registers">
                <Link href={route('mail.incoming.index')} className={direction === 'incoming' ? 'active' : ''}>
                    <Inbox aria-hidden="true" /> Incoming <span>{props.stats.incoming_total}</span>
                </Link>
                <Link href={route('mail.outgoing.index')} className={direction === 'outgoing' ? 'active' : ''}>
                    <Send aria-hidden="true" /> Outgoing <span>{props.stats.outgoing_total}</span>
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
                <select className="select" value={filters.status} onChange={(event) => applyFilters({ status: event.target.value })} aria-label="Correspondence status">
                    <option value="">All statuses</option>
                    {props.statusOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
                <select className="select" value={filters.department_id} onChange={(event) => applyFilters({ department_id: event.target.value })} aria-label="Department">
                    <option value="">All departments</option>
                    {props.departmentOptions.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
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
                                <th>{direction === 'incoming' ? 'Received' : 'Sent'}</th>
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
                                    <td className="ref">{mail.register_number}</td>
                                    <td>{mail.sender_name}</td>
                                    <td>
                                        <strong>{mail.subject}</strong>
                                        {mail.correspondence_reference && (
                                            <small className="mail-cell-meta">Ref: {mail.correspondence_reference}</small>
                                        )}
                                    </td>
                                    <td>{mail.mail_date_label}</td>
                                    <td>{mail.recipient_name}</td>
                                    <td>
                                        <span className={`badge ${mail.status_class}`}>{mail.status}</span>
                                        <small className="mail-cell-meta">{mail.priority} · FY {mail.financial_year ?? '—'}</small>
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
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({
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

function MailDetailPanel({ mail, props, onClose }: { mail: MailDetail; props: Props; onClose: () => void }) {
    const [preview, setPreview] = useState<MailAttachment | null>(null);
    const [editing, setEditing] = useState(false);
    return (
        <Slideover
            size="wide"
            onClose={onClose}
            header={
                <div className="task-view-heading">
                    <div className="task-view-eyebrow">
                        <span>{mail.direction} mail</span>
                        <span>{mail.register_number}</span>
                    </div>
                    <h2>{mail.subject}</h2>
                    <div className="task-view-badges">
                        <span className={`badge ${mail.status_class}`}>{mail.status}</span>
                        <span className="badge muted">{mail.confidentiality}</span>
                    </div>
                </div>
            }
        >
            <div className="mail-detail-layout">
                <main className="mail-detail-main">
                    <section className="card mail-details-copy">
                        <div className="task-section-heading">
                            <div>
                                <span className="result-eyebrow">Correspondence details</span>
                                <h3>Correspondence summary</h3>
                            </div>
                            {mail.can_edit && (
                                <button type="button" className="btn btn-ghost" onClick={() => setEditing(true)}>
                                    <Pencil /> Edit correspondence
                                </button>
                            )}
                        </div>
                        <p>{mail.details || 'No additional details were provided.'}</p>
                    </section>
                    <section className="card">
                        <div className="task-section-heading">
                            <div>
                                <span className="result-eyebrow">Source documents</span>
                                <h3>Attachments</h3>
                            </div>
                            <span className="badge">{mail.attachments.length}</span>
                        </div>
                        {mail.attachments.length === 0 ? (
                            <EmptyState>No files were attached.</EmptyState>
                        ) : (
                            <div className="mail-attachment-list">
                                {mail.attachments.map((file) => (
                                    <article key={file.id}>
                                        <span>
                                            <FileText />
                                        </span>
                                        <div>
                                            <strong>{file.filename}</strong>
                                            <small>
                                                {file.size_label} · {file.uploaded_by}
                                            </small>
                                        </div>
                                        <div>
                                            {file.preview_url && (
                                                <button type="button" className="btn btn-ghost" onClick={() => setPreview(file)}>
                                                    <Eye /> Preview
                                                </button>
                                            )}
                                            <a className="btn btn-ghost" href={file.download_url}>
                                                <Download /> Download
                                            </a>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}
                    </section>
                    {mail.can_edit && <CorrespondenceWorkflowForm mail={mail} statusOptions={props.statusOptions} />}
                    <section className="card mail-history-card">
                        <div className="task-section-heading">
                            <div>
                                <span className="result-eyebrow">Permanent audit trail</span>
                                <h3>Correspondence activity</h3>
                            </div>
                            <span className="badge">
                                <History /> {mail.activity_history.length}
                            </span>
                        </div>
                        {mail.activity_history.length === 0 ? (
                            <EmptyState>No correspondence activity has been recorded.</EmptyState>
                        ) : (
                            <div className="mail-history-list">
                                {mail.activity_history.map((entry) => (
                                    <article key={entry.id}>
                                        <div className="mail-history-meta">
                                            <span>
                                                <History />
                                            </span>
                                            <div>
                                                <strong>{entry.action}</strong>
                                                <small>{entry.performed_by} · {entry.performed_at_label}</small>
                                            </div>
                                        </div>
                                        <div className="mail-history-changes">
                                            {entry.changes.map((change) => (
                                                <div key={change.field}>
                                                    <strong>{change.field}</strong>
                                                    <p>
                                                        <span>{change.before}</span>
                                                        <ArrowRight />
                                                        <span>{change.after}</span>
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}
                    </section>
                    {mail.can_assign && <AssignMailForm mail={mail} props={props} />}
                    {mail.task_url && (
                        <section className="mail-task-link">
                            <div>
                                <span className="result-eyebrow">Tracked assignment</span>
                                <strong>
                                    {mail.task_reference} · {mail.assigned_to_name}
                                </strong>
                            </div>
                            <Link className="btn btn-primary" href={mail.task_url}>
                                Open assignment <ArrowRight />
                            </Link>
                        </section>
                    )}
                </main>
                <aside className="task-details-card">
                    <div className="task-details-heading">
                        <span className="result-eyebrow">Registry information</span>
                        <h3>Key details</h3>
                    </div>
                    <div className="task-details-list">
                        <Detail icon={<UserRound />} label="From" value={mail.sender_name} />
                        <Detail icon={<Send />} label="To" value={mail.recipient_name} />
                        <Detail icon={<CalendarDays />} label={mail.direction === 'incoming' ? 'Received' : 'Sent'} value={mail.mail_date_label} />
                        <Detail icon={<FileText />} label="Sender reference" value={mail.correspondence_reference || 'Not provided'} />
                        <Detail icon={<Building2 />} label="Organisation" value={mail.sender_organisation || 'Not provided'} />
                        <Detail icon={<Building2 />} label="Official office" value={mail.office_name} />
                        {mail.prepared_on_behalf_of && (
                            <Detail icon={<ShieldCheck />} label="Prepared on behalf of" value={mail.prepared_on_behalf_of} />
                        )}
                        <Detail icon={<ShieldCheck />} label="Priority / financial year" value={`${mail.priority} · ${mail.financial_year ?? '—'}`} />
                        {mail.dispatch_reference && (
                            <Detail icon={<Send />} label="Dispatch" value={`${mail.dispatch_method ?? 'Recorded'} · ${mail.dispatch_reference}`} />
                        )}
                        <Detail icon={<ShieldCheck />} label="Captured by" value={`${mail.captured_by} · ${mail.captured_at_label}`} />
                        <Detail icon={<History />} label="Last processed by" value={mail.last_processed_by || mail.captured_by} />
                    </div>
                </aside>
            </div>
            {preview && <AttachmentPreview attachment={preview} onClose={() => setPreview(null)} />}
            {editing && <EditMailModal mail={mail} onClose={() => setEditing(false)} />}
        </Slideover>
    );
}

function Detail({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
    return (
        <div className="task-detail-item">
            <span className="task-detail-icon">{icon}</span>
            <div>
                <span>{label}</span>
                <strong>{value}</strong>
            </div>
        </div>
    );
}

function CorrespondenceWorkflowForm({ mail, statusOptions }: { mail: MailDetail; statusOptions: SelectOption[] }) {
    const form = useForm({
        status: mail.status_value,
        note: '',
        dispatch_method: mail.dispatch_method ?? '',
        dispatch_reference: mail.dispatch_reference ?? '',
        dispatched_at: '',
    });
    const options = statusOptions.filter((option) => mail.can_approve || !['approved', 'rejected'].includes(option.value));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('mail.transition', mail.id), { preserveScroll: true });
    };

    return (
        <section className="card mail-workflow-card">
            <div className="task-form-heading">
                <div>
                    <span className="result-eyebrow">Office workflow</span>
                    <h3>Process correspondence</h3>
                </div>
                <span>Every status change is attributed to you in the permanent audit trail.</span>
            </div>
            <form onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <Field label="Next status" required>
                        <select className="select" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                            {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                    </Field>
                    <Field label="Instruction or processing note">
                        <input className="input" value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} />
                    </Field>
                    {form.data.status === 'dispatched' && (
                        <>
                            <Field label="Dispatch method">
                                <input className="input" placeholder="Courier, email, hand delivery…" value={form.data.dispatch_method} onChange={(event) => form.setData('dispatch_method', event.target.value)} />
                            </Field>
                            <Field label="Dispatch reference">
                                <input className="input" value={form.data.dispatch_reference} onChange={(event) => form.setData('dispatch_reference', event.target.value)} />
                            </Field>
                            <Field label="Dispatched at">
                                <input className="input" type="datetime-local" value={form.data.dispatched_at} onChange={(event) => form.setData('dispatched_at', event.target.value)} />
                            </Field>
                        </>
                    )}
                </div>
                <button type="submit" className="btn btn-primary" disabled={form.processing || form.data.status === mail.status_value}>
                    Update correspondence status
                </button>
            </form>
        </section>
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
                            onChange={(e) => mail.direction === 'incoming'
                                ? form.setData('received_date', e.target.value)
                                : form.setData('sent_date', e.target.value)}
                        />
                    </Field>
                    {mail.direction === 'incoming' && (
                        <Field label="Receipt method">
                            <select className="select" value={form.data.receipt_method} onChange={(e) => form.setData('receipt_method', e.target.value)}>
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

function AssignMailForm({ mail, props }: { mail: MailDetail; props: Props }) {
    const form = useForm({ department_id: '', assigned_to_user_id: '', priority: 'medium', due_date: '', instructions: '', workstream_id: '' });
    const [recipient, setRecipient] = useState<RecipientSuggestion | null>(null);
    const selectRecipient = (next: RecipientSuggestion | null) => {
        setRecipient(next);
        form.setData('assigned_to_user_id', next === null ? '' : String(next.id));
        form.setData('department_id', next?.department_id === null || next?.department_id === undefined ? '' : String(next.department_id));
        form.clearErrors('assigned_to_user_id', 'department_id');
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('mail.assign', mail.id));
    };
    return (
        <section className="card mail-assign-card">
            <div className="task-form-heading">
                <div>
                    <span className="result-eyebrow">Assign for action</span>
                    <h3>Create a tracked assignment</h3>
                </div>
                <span>The mail details become the assignment brief and remain linked to this source.</span>
            </div>
            <form onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <RecipientPicker
                        mailId={mail.id}
                        selected={recipient}
                        onSelect={selectRecipient}
                        error={form.errors.assigned_to_user_id || form.errors.department_id}
                    />
                    <Field label="Priority">
                        <select className="select" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                            {props.priorityOptions.map((p) => (
                                <option key={p.value} value={p.value}>
                                    {p.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Due date">
                        <input className="input" type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                    </Field>
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
                    <Field label="Action instructions" wide>
                        <textarea
                            className="textarea"
                            rows={3}
                            value={form.data.instructions}
                            onChange={(e) => form.setData('instructions', e.target.value)}
                        />
                    </Field>
                </div>
                <button type="submit" className="btn btn-primary" disabled={form.processing || recipient === null}>
                    <ArrowRight /> Create assignment
                </button>
            </form>
        </section>
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
