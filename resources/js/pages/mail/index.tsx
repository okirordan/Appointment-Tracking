import AnnotationTitlePicker, { type AnnotationTitleOption } from '@/components/ats/annotation-title-picker';
import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import MailDuplicateSuggestions from '@/components/ats/mail-duplicate-suggestions';
import Modal from '@/components/ats/modal';
import Pagination from '@/components/ats/pagination';
import ProgressBar from '@/components/ats/progress-bar';
import RecipientPicker, { type RecipientSuggestion } from '@/components/ats/recipient-picker';
import Slideover from '@/components/ats/slideover';
import {
    AlertCircle,
    Archive,
    ArrowRight,
    Building2,
    CalendarDays,
    ChevronDown,
    Download,
    ExternalLink,
    Eye,
    FileText,
    FolderOpen,
    Forward,
    Inbox,
    Info,
    LoaderCircle,
    Mail,
    MessageSquarePlus,
    MessageSquareText,
    Paperclip,
    Pencil,
    Plus,
    Printer,
    Save,
    Search,
    Send,
    ShieldCheck,
    Trash2,
    UploadCloud,
    UserMinus,
    UserRoundCheck,
    UsersRound,
    Workflow,
} from '@/components/icons';
import { useConfirm } from '@/hooks/use-confirm';
import type { PaginatedData, SelectOption } from '@/types';
import type { PendingVisit } from '@inertiajs/core';
import { Link, router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';

function todayForDateInput(): string {
    const now = new Date();
    const localDate = new Date(now.getTime() - now.getTimezoneOffset() * 60_000);

    return localDate.toISOString().slice(0, 10);
}

function createUuidV4(): string {
    const cryptoApi = globalThis.crypto;

    if (typeof cryptoApi?.randomUUID === 'function') {
        return cryptoApi.randomUUID();
    }

    const bytes = new Uint8Array(16);
    if (typeof cryptoApi?.getRandomValues === 'function') {
        cryptoApi.getRandomValues(bytes);
    } else {
        for (let index = 0; index < bytes.length; index += 1) {
            bytes[index] = Math.floor(Math.random() * 256);
        }
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'));

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

function staffPartyLabel(recipient: RecipientSuggestion): string {
    return recipient.title ? `${recipient.name} — ${recipient.title}` : recipient.name;
}

function isBackgroundInertiaVisit(visit: PendingVisit): boolean {
    if (visit.prefetch) return true;

    return visit.method === 'get' && visit.only.length === 1 && visit.only[0] === 'notifications';
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
    filed_at_label: string | null;
    filed_office: string | null;
    filing_category: string | null;
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
    priority: string;
    priority_class: string;
    instructions: string | null;
    assigned_at_label: string | null;
    due_date_label: string | null;
    is_overdue: boolean;
    completed_at_label: string | null;
    unassignments: AssignmentUnassignment[];
}

interface ActivityEntry {
    id: string;
    message: string;
    origin_title: string | null;
    recipient_title: string | null;
    author_name: string;
    author_title: string;
    author_office: string;
    recorded_at_label: string | null;
    attachments: { filename: string; download_url: string }[];
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
    forward_origin_title: AnnotationTitleOption | null;
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
    filing: {
        filed_by: string;
        filed_at_label: string | null;
        office: string;
        category: string | null;
        note: string | null;
        is_current: boolean;
    } | null;
    can_assign: boolean;
    can_edit: boolean;
    can_participate: boolean;
    can_approve: boolean;
    can_unassign: boolean;
    can_assign_outgoing: boolean;
    can_file: boolean;
    can_reopen: boolean;
    forward_block_reason: string | null;
}

interface Props {
    direction: 'incoming' | 'outgoing' | 'filed';
    registerOfficeName: string;
    canViewRegister: boolean;
    canManageRegister: boolean;
    canCreateOutgoingAssignment: boolean;
    filters: {
        q: string;
        status: string;
        priority: string;
        department_id: string;
        assigned_to_user_id: string;
        financial_year: string;
        date_from: string;
        date_to: string;
        category: string;
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
        filed_total: number;
    };
    mails: PaginatedData<MailRow>;
    selectedMail: MailDetail | null;
    priorityOptions: SelectOption[];
    statusOptions: SelectOption[];
    financialYearOptions: string[];
    filingCategoryOptions: string[];
    departmentOptions: { id: number; name: string }[];
    officerOptions: { id: number; full_name: string; title: string }[];
    workstreamOptions: { id: number; name: string; type: string }[];
    mailFeatures: Record<
        | 'correspondence_reference'
        | 'receipt_method'
        | 'confidentiality'
        | 'registry'
        | 'initial_status'
        | 'registry_file_number'
        | 'project_programme'
        | 'priority'
        | 'register_number'
        | 'forwarding_due_date',
        boolean
    >;
}

type MailPartySelection = 'staff' | 'shorthand' | 'external';

export default function MailIndex(props: Props) {
    const { direction, filters, mails, selectedMail } = props;
    const [showCapture, setShowCapture] = useState(false);
    const [query, setQuery] = useState(filters.q);
    const title =
        direction === 'incoming'
            ? 'Active Incoming Correspondence'
            : direction === 'filed'
              ? 'Filed Correspondence'
              : 'Outgoing / Forwarded Correspondence';
    const indexRoute = direction === 'incoming' ? 'mail.incoming.index' : direction === 'filed' ? 'mail.filed.index' : 'mail.outgoing.index';

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
                    {props.mailFeatures.registry && <span className="result-eyebrow">{props.registerOfficeName}</span>}
                    <h1>{title}</h1>
                </div>
                {props.canManageRegister && direction !== 'filed' && (
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
                <Link href={route('mail.filed.index')} className={direction === 'filed' ? 'active' : ''}>
                    <Archive aria-hidden="true" /> Filed <span>{props.stats.filed_total}</span>
                </Link>
            </nav>

            <div className="mail-filters" role="search">
                <div className="mail-filters-search">
                    <Search aria-hidden="true" />
                    <input
                        className="input"
                        type="search"
                        aria-label="Search mail"
                        placeholder="Search subject, sender, recipient or reference…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => event.key === 'Enter' && applyFilters({ q: query })}
                    />
                </div>
                <div className="mail-filters-controls">
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
                    {direction === 'filed' && (
                        <select
                            className="select"
                            value={filters.category}
                            onChange={(event) => applyFilters({ category: event.target.value })}
                            aria-label="Filing category"
                        >
                            <option value="">All categories</option>
                            {props.filingCategoryOptions.map((category) => (
                                <option key={category} value={category}>
                                    {category}
                                </option>
                            ))}
                        </select>
                    )}
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
                </div>
                <div className="mail-filters-actions">
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
                                category: '',
                            });
                        }}
                    >
                        Clear
                    </button>
                </div>
            </div>

            <div className="card mail-table-card">
                <div className="table-scroll">
                    <table className="tbl mail-table">
                        <thead>
                            <tr>
                                {props.mailFeatures.register_number && <th className="col-register">Register No.</th>}
                                <th className="col-subject">Subject</th>
                                <th className="col-party">{direction === 'outgoing' ? 'Prepared by' : 'From'}</th>
                                <th className="col-party">{direction === 'filed' ? 'Filed in' : 'To'}</th>
                                <th className="col-date">
                                    {direction === 'incoming' ? 'Received' : direction === 'filed' ? 'Filed on' : 'Last activity'}
                                </th>
                                <th className="col-status">Status</th>
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
                                    {props.mailFeatures.register_number && (
                                        <td className="col-register">
                                            <span className="ref">{mail.register_number}</span>
                                            <small className="mail-cell-meta">{mail.record_kind}</small>
                                        </td>
                                    )}
                                    <td className="col-subject">
                                        <strong>{mail.subject}</strong>
                                        {props.mailFeatures.correspondence_reference && mail.correspondence_reference && (
                                            <small className="mail-cell-meta">Ref: {mail.correspondence_reference}</small>
                                        )}
                                    </td>
                                    <td className="col-party">{mail.sender_name}</td>
                                    <td className="col-party">
                                        {direction === 'filed' ? (mail.filed_office ?? mail.recipient_display) : mail.recipient_display}
                                    </td>
                                    <td className="col-date">
                                        {direction === 'incoming'
                                            ? mail.mail_date_label
                                            : direction === 'filed'
                                              ? (mail.filed_at_label ?? mail.mail_date_label)
                                              : mail.activity_date_label}
                                    </td>
                                    <td className="col-status">
                                        <div className="mail-status-cell">
                                            <span className={`badge ${mail.status_class}`}>{mail.status}</span>
                                            {props.mailFeatures.priority && <span className={`badge ${mail.priority_class}`}>{mail.priority}</span>}
                                        </div>
                                        {direction === 'filed' && mail.filing_category && (
                                            <small className="mail-cell-meta">{mail.filing_category}</small>
                                        )}
                                        {mail.task_reference && <small className="mail-cell-meta">{mail.task_reference}</small>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {mails.data.length === 0 && <EmptyState>No mail records match your filters.</EmptyState>}
                <div className="mail-table-foot">
                    <Pagination meta={mails.meta} only={['filters', 'mails']} />
                </div>
            </div>

            {showCapture && direction !== 'filed' && (
                <CaptureMailModal
                    direction={direction}
                    canAssignOutgoing={props.canCreateOutgoingAssignment}
                    features={props.mailFeatures}
                    onClose={() => setShowCapture(false)}
                />
            )}
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

function CaptureMailModal({
    direction,
    canAssignOutgoing,
    features,
    onClose,
}: {
    direction: 'incoming' | 'outgoing';
    canAssignOutgoing: boolean;
    features: Props['mailFeatures'];
    onClose: () => void;
}) {
    const today = todayForDateInput();
    const confirm = useConfirm();
    const [submissionToken] = useState(createUuidV4);
    const form = useForm({
        submission_token: submissionToken,
        register_number: '',
        sender_name: '',
        sender_organisation: '',
        source_type: (direction === 'incoming' ? 'internal' : '') as 'internal' | 'external' | '',
        source_directory_type: 'shorthand' as 'shorthand' | 'staff',
        annotation_title_id: '' as number | '',
        source_staff_user_id: '' as number | '',
        external_source: '',
        destination_type: (direction === 'incoming' ? 'internal' : 'external') as 'internal' | 'external',
        destination_directory_type: 'shorthand' as 'shorthand' | 'staff',
        recipient_annotation_title_id: '' as number | '',
        recipient_staff_user_id: '' as number | '',
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
        requires_follow_up: false as boolean,
        copied_for_information: false as boolean,
        assigned_to_user_id: '' as number | '',
        cc_user_ids: [] as number[],
        instructions: '',
        due_date: '',
        duplicate_override: false as boolean,
        duplicate_reason: '',
    });
    const [responsibleOfficer, setResponsibleOfficer] = useState<RecipientSuggestion | null>(null);
    const [outgoingCc, setOutgoingCc] = useState<RecipientSuggestion[]>([]);
    const [internalSource, setInternalSource] = useState<AnnotationTitleOption | null>(null);
    const internalSourceRef = useRef<AnnotationTitleOption | null>(null);
    const [internalSourceStaff, setInternalSourceStaff] = useState<RecipientSuggestion | null>(null);
    const internalSourceStaffRef = useRef<RecipientSuggestion | null>(null);
    const [destinationTitle, setDestinationTitle] = useState<AnnotationTitleOption | null>(null);
    const destinationTitleRef = useRef<AnnotationTitleOption | null>(null);
    const [destinationStaff, setDestinationStaff] = useState<RecipientSuggestion | null>(null);
    const destinationStaffRef = useRef<RecipientSuggestion | null>(null);
    const guardState = useRef({ dirty: false, processing: false, submitting: false });
    const formActions = useRef({ reset: form.reset, clearErrors: form.clearErrors });
    const confirmOpen = useRef(false);
    const bypassNextVisit = useRef(false);
    guardState.current.dirty = form.isDirty;
    guardState.current.processing = form.processing;
    formActions.current = { reset: form.reset, clearErrors: form.clearErrors };

    const discardThen = useCallback(
        async (action: () => void) => {
            if (confirmOpen.current) return;
            confirmOpen.current = true;
            const discard = await confirm({
                title: 'Discard unsaved changes?',
                message: 'The information entered in this correspondence form has not been saved. You can continue editing or discard it.',
                confirmLabel: 'Discard changes',
                cancelLabel: 'Continue editing',
                variant: 'danger',
            });
            confirmOpen.current = false;
            if (!discard) return;

            guardState.current.dirty = false;
            formActions.current.reset();
            formActions.current.clearErrors();
            action();
        },
        [confirm],
    );

    const requestClose = () => {
        if (guardState.current.processing || guardState.current.submitting) return;
        if (!guardState.current.dirty) {
            onClose();
            return;
        }
        void discardThen(onClose);
    };

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            const state = guardState.current;
            if (!state.dirty || state.processing || state.submitting) return;
            event.preventDefault();
            event.returnValue = '';
        };
        const removeInertiaGuard = router.on('before', (event) => {
            if (isBackgroundInertiaVisit(event.detail.visit)) return;

            if (bypassNextVisit.current) {
                bypassNextVisit.current = false;
                return;
            }
            const state = guardState.current;
            if (!state.dirty || state.processing || state.submitting) return;

            event.preventDefault();
            const pendingVisit: PendingVisit = event.detail.visit;
            void discardThen(() => {
                const options = { ...pendingVisit };
                onClose();
                bypassNextVisit.current = true;
                router.visit(pendingVisit.url, options);
            });
        });
        window.addEventListener('beforeunload', warn);
        return () => {
            removeInertiaGuard();
            window.removeEventListener('beforeunload', warn);
        };
    }, [discardThen, onClose]);

    const setIncomingSourceSelection = (selection: MailPartySelection) => {
        internalSourceRef.current = null;
        internalSourceStaffRef.current = null;
        setInternalSource(null);
        setInternalSourceStaff(null);
        form.setData((current) => ({
            ...current,
            source_type: selection === 'external' ? 'external' : 'internal',
            source_directory_type: selection === 'staff' ? 'staff' : 'shorthand',
            annotation_title_id: '',
            source_staff_user_id: '',
            external_source: '',
            sender_name: '',
        }));
        form.clearErrors('source_type', 'source_directory_type', 'annotation_title_id', 'source_staff_user_id', 'external_source', 'sender_name');
    };

    const setIncomingSourceType = (sourceType: 'internal' | 'external') => {
        setIncomingSourceSelection(sourceType === 'internal' ? 'shorthand' : 'external');
    };

    const selectInternalSource = (source: AnnotationTitleOption | null) => {
        if (source !== null && (!Number.isInteger(source.id) || source.id < 1)) {
            internalSourceRef.current = null;
            setInternalSource(null);
            form.setData('annotation_title_id', '');
            form.setError('annotation_title_id', 'The selected internal source is invalid. Search the shared directory and select it again.');
            return;
        }

        internalSourceRef.current = source;
        setInternalSource(source);
        form.setData((current) => ({
            ...current,
            annotation_title_id: source?.id ?? '',
            sender_name: source?.label ?? '',
            external_source: '',
        }));
        form.clearErrors('source_type', 'annotation_title_id', 'external_source', 'sender_name');
    };

    const selectInternalSourceStaff = (staff: RecipientSuggestion | null) => {
        if (staff !== null && staff.assignment_target_type !== 'individual') return;
        internalSourceStaffRef.current = staff;
        setInternalSourceStaff(staff);
        form.setData((current) => ({
            ...current,
            source_staff_user_id: staff?.id ?? '',
            sender_name: staff === null ? '' : staffPartyLabel(staff),
            annotation_title_id: '',
            external_source: '',
        }));
        form.clearErrors('source_type', 'source_directory_type', 'source_staff_user_id', 'sender_name');
    };

    const setDestinationSelection = (selection: MailPartySelection) => {
        destinationTitleRef.current = null;
        destinationStaffRef.current = null;
        setDestinationTitle(null);
        setDestinationStaff(null);
        form.setData((current) => ({
            ...current,
            destination_type: selection === 'external' ? 'external' : 'internal',
            destination_directory_type: selection === 'staff' ? 'staff' : 'shorthand',
            recipient_annotation_title_id: '',
            recipient_staff_user_id: '',
            recipient_name: '',
        }));
        form.clearErrors(
            'destination_type',
            'destination_directory_type',
            'recipient_annotation_title_id',
            'recipient_staff_user_id',
            'recipient_name',
        );
    };

    const setIncomingDestinationType = (destinationType: 'internal' | 'external') => {
        setDestinationSelection(destinationType === 'internal' ? 'shorthand' : 'external');
    };

    const selectDestinationTitle = (destination: AnnotationTitleOption | null) => {
        if (destination !== null && (!Number.isInteger(destination.id) || destination.id < 1)) {
            destinationTitleRef.current = null;
            setDestinationTitle(null);
            form.setData('recipient_annotation_title_id', '');
            form.setError('recipient_annotation_title_id', 'The selected destination is invalid. Search the shared directory and select it again.');
            return;
        }

        destinationTitleRef.current = destination;
        setDestinationTitle(destination);
        form.setData((current) => ({
            ...current,
            recipient_annotation_title_id: destination?.id ?? '',
            recipient_name: destination?.label ?? '',
        }));
        form.clearErrors('destination_type', 'recipient_annotation_title_id', 'recipient_name');
    };

    const selectDestinationStaff = (staff: RecipientSuggestion | null) => {
        if (staff !== null && staff.assignment_target_type !== 'individual') return;
        destinationStaffRef.current = staff;
        setDestinationStaff(staff);
        form.setData((current) => ({
            ...current,
            recipient_staff_user_id: staff?.id ?? '',
            recipient_name: staff === null ? '' : staffPartyLabel(staff),
            recipient_annotation_title_id: '',
        }));
        form.clearErrors('destination_type', 'destination_directory_type', 'recipient_staff_user_id', 'recipient_name');
    };

    const selectResponsibleOfficer = (recipient: RecipientSuggestion | null) => {
        if (recipient !== null && outgoingCc.some((cc) => cc.id === recipient.id)) return;
        setResponsibleOfficer(recipient);
        form.setData('assigned_to_user_id', recipient?.id ?? '');
        form.clearErrors('assigned_to_user_id');
    };
    const selectOutgoingCc = (recipient: RecipientSuggestion | null) => {
        if (
            recipient === null ||
            recipient.assignment_target_type !== 'individual' ||
            recipient.id === responsibleOfficer?.id ||
            outgoingCc.some((cc) => cc.id === recipient.id)
        )
            return;
        const selected = [...outgoingCc, recipient];
        setOutgoingCc(selected);
        form.setData(
            'cc_user_ids',
            selected.map((cc) => cc.id),
        );
        form.clearErrors('cc_user_ids');
    };
    const removeOutgoingCc = (recipientId: number) => {
        const selected = outgoingCc.filter((cc) => cc.id !== recipientId);
        setOutgoingCc(selected);
        form.setData(
            'cc_user_ids',
            selected.map((cc) => cc.id),
        );
    };
    const setFollowUpRequired = (required: boolean) => {
        form.setData((current) => ({
            ...current,
            requires_follow_up: required,
            status: required ? 'dispatched' : current.status,
            assigned_to_user_id: required ? current.assigned_to_user_id : '',
            cc_user_ids: required ? current.cc_user_ids : [],
            instructions: required ? current.instructions : '',
            due_date: required ? current.due_date : '',
        }));
        if (!required) {
            setResponsibleOfficer(null);
            setOutgoingCc([]);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (form.processing || guardState.current.submitting) return;

        const selectedSource = internalSourceRef.current;
        const selectedSourceStaff = internalSourceStaffRef.current;
        if (
            direction === 'incoming' &&
            form.data.source_type === 'internal' &&
            form.data.source_directory_type === 'shorthand' &&
            selectedSource === null
        ) {
            form.setError('annotation_title_id', 'Select an officer title from the shared directory.');
            return;
        }
        if (
            direction === 'incoming' &&
            form.data.source_type === 'internal' &&
            form.data.source_directory_type === 'staff' &&
            selectedSourceStaff === null
        ) {
            form.setError('source_staff_user_id', 'Select an officer name from the staff directory.');
            return;
        }
        const selectedDestination = destinationTitleRef.current;
        const selectedDestinationStaff = destinationStaffRef.current;
        if (form.data.destination_type === 'internal' && form.data.destination_directory_type === 'shorthand' && selectedDestination === null) {
            form.setError('recipient_annotation_title_id', 'Select a receiving officer title from the shared directory.');
            return;
        }
        if (form.data.destination_type === 'internal' && form.data.destination_directory_type === 'staff' && selectedDestinationStaff === null) {
            form.setError('recipient_staff_user_id', 'Select a receiving officer name from the staff directory.');
            return;
        }

        form.transform((data) => ({
            ...data,
            source_directory_type: direction === 'incoming' && data.source_type === 'internal' ? data.source_directory_type : '',
            annotation_title_id:
                direction === 'incoming' && data.source_type === 'internal' && data.source_directory_type === 'shorthand'
                    ? (selectedSource?.id ?? '')
                    : '',
            source_staff_user_id:
                direction === 'incoming' && data.source_type === 'internal' && data.source_directory_type === 'staff'
                    ? (selectedSourceStaff?.id ?? '')
                    : '',
            sender_name:
                direction === 'incoming' && data.source_type === 'internal'
                    ? data.source_directory_type === 'staff' && selectedSourceStaff !== null
                        ? staffPartyLabel(selectedSourceStaff)
                        : (selectedSource?.label ?? '')
                    : data.sender_name,
            external_source: direction === 'incoming' && data.source_type === 'internal' ? '' : data.external_source,
            destination_directory_type: data.destination_type === 'internal' ? data.destination_directory_type : '',
            recipient_annotation_title_id:
                data.destination_type === 'internal' && data.destination_directory_type === 'shorthand' ? (selectedDestination?.id ?? '') : '',
            recipient_staff_user_id:
                data.destination_type === 'internal' && data.destination_directory_type === 'staff' ? (selectedDestinationStaff?.id ?? '') : '',
            recipient_name:
                data.destination_type === 'internal'
                    ? data.destination_directory_type === 'staff' && selectedDestinationStaff !== null
                        ? staffPartyLabel(selectedDestinationStaff)
                        : (selectedDestination?.label ?? '')
                    : data.recipient_name,
        }));
        guardState.current.submitting = true;
        form.post(route(direction === 'incoming' ? 'mail.incoming.store' : 'mail.outgoing.store'), {
            forceFormData: true,
            onSuccess: () => {
                guardState.current.dirty = false;
                form.reset();
                form.clearErrors();
                onClose();
            },
            onError: () => {
                guardState.current.submitting = false;
            },
            onCancel: () => {
                guardState.current.submitting = false;
            },
            onFinish: () => {
                guardState.current.submitting = false;
            },
        });
    };

    const renderCaptureMetadataFields = () => (
        <>
            {features.register_number && (
                <Field label="Internal register number">
                    <input
                        className="input"
                        value={form.data.register_number}
                        onChange={(event) => form.setData('register_number', event.target.value)}
                    />
                </Field>
            )}
            {features.correspondence_reference && (
                <Field label="Correspondence reference">
                    <input
                        className="input"
                        value={form.data.correspondence_reference}
                        onChange={(event) => form.setData('correspondence_reference', event.target.value)}
                    />
                </Field>
            )}
            {features.priority && (
                <Field label="Priority">
                    <select className="select" value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)}>
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </Field>
            )}
            {features.initial_status && (
                <Field label="Initial status">
                    <select className="select" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
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
            )}
            {features.confidentiality && (
                <Field label="Confidentiality">
                    <select
                        className="select"
                        value={form.data.confidentiality}
                        onChange={(event) => form.setData('confidentiality', event.target.value)}
                    >
                        <option value="normal">Normal</option>
                        <option value="confidential">Confidential</option>
                        <option value="restricted">Restricted</option>
                    </select>
                </Field>
            )}
            {features.registry_file_number && (
                <Field label="Registry file number">
                    <input
                        className="input"
                        value={form.data.registry_file_number}
                        onChange={(event) => form.setData('registry_file_number', event.target.value)}
                    />
                </Field>
            )}
        </>
    );

    return (
        <Modal
            title={direction === 'incoming' ? 'Record Incoming Correspondence' : 'Record outgoing correspondence'}
            description={direction === 'incoming' ? 'Log new mail into the GovATS system.' : undefined}
            size="wide"
            className={direction === 'incoming' ? 'incoming-capture-modal' : 'incoming-capture-modal outgoing-capture-modal'}
            onClose={requestClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={requestClose} disabled={form.processing}>
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="mail-capture-form"
                        className="btn btn-primary"
                        disabled={form.processing || (form.data.requires_follow_up && responsibleOfficer === null)}
                    >
                        {form.processing ? (
                            <LoaderCircle className="spin" aria-hidden="true" />
                        ) : direction === 'incoming' ? (
                            <Save aria-hidden="true" />
                        ) : (
                            <Send aria-hidden="true" />
                        )}
                        {form.processing
                            ? 'Saving correspondence…'
                            : form.data.requires_follow_up
                              ? 'Save and assign follow-up'
                              : direction === 'incoming' && form.data.copied_for_information
                                ? 'Save and file copied mail'
                                : 'Save correspondence'}
                    </button>
                </>
            }
        >
            <form id="mail-capture-form" onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                {direction === 'incoming' ? (
                    <div className="incoming-capture-layout">
                        <div className="incoming-capture-columns">
                            <div className="incoming-capture-stack">
                                <IncomingFormSection title="From" icon={<Mail aria-hidden="true" />}>
                                    <div className="incoming-section-grid">
                                        <Field label="From" required wide={form.data.source_type !== 'internal'}>
                                            <select
                                                className="select"
                                                value={form.data.source_type}
                                                onChange={(event) => setIncomingSourceType(event.target.value as 'internal' | 'external')}
                                            >
                                                <option value="internal">Internal Ministry Correspondence</option>
                                                <option value="external">External Source</option>
                                            </select>
                                            {form.errors.source_type && <small className="field-error">{form.errors.source_type}</small>}
                                        </Field>

                                        {form.data.source_type === 'internal' ? (
                                            <>
                                                <Field label="Department/Officer" required>
                                                    <select
                                                        className="select"
                                                        value={form.data.source_directory_type}
                                                        onChange={(event) => setIncomingSourceSelection(event.target.value as MailPartySelection)}
                                                    >
                                                        <option value="shorthand">Officer Title</option>
                                                        <option value="staff">Officer Name</option>
                                                    </select>
                                                    {form.errors.source_directory_type && (
                                                        <small className="field-error">{form.errors.source_directory_type}</small>
                                                    )}
                                                </Field>
                                                {form.data.source_directory_type === 'staff' ? (
                                                    <RecipientPicker
                                                        selected={internalSourceStaff}
                                                        onSelect={selectInternalSourceStaff}
                                                        searchRoute={route('mail.party-search')}
                                                        allowGroups={false}
                                                        compactSelected
                                                        label="Officer Name"
                                                        placeholder="Search officer name, staff number, title, or department"
                                                        error={form.errors.source_staff_user_id || form.errors.sender_name}
                                                    />
                                                ) : (
                                                    <div className="incoming-directory-field mail-field-wide">
                                                        <AnnotationTitlePicker
                                                            label="Officer Title"
                                                            selected={internalSource}
                                                            onSelect={selectInternalSource}
                                                            placeholder="Search officer title or shorthand"
                                                            error={form.errors.annotation_title_id || form.errors.sender_name}
                                                        />
                                                    </div>
                                                )}
                                            </>
                                        ) : (
                                            <Field label="Individual Name" required wide>
                                                <input
                                                    className="input"
                                                    value={form.data.external_source}
                                                    onChange={(event) =>
                                                        form.setData((current) => ({
                                                            ...current,
                                                            external_source: event.target.value,
                                                            sender_name: event.target.value,
                                                            annotation_title_id: '',
                                                            source_staff_user_id: '',
                                                        }))
                                                    }
                                                    placeholder="Enter individual name"
                                                />
                                                {(form.errors.external_source || form.errors.sender_name) && (
                                                    <small className="field-error">{form.errors.external_source || form.errors.sender_name}</small>
                                                )}
                                            </Field>
                                        )}

                                        <Field label="Ministry Department / Organization" wide>
                                            <input
                                                className="input"
                                                value={form.data.sender_organisation}
                                                onChange={(event) => form.setData('sender_organisation', event.target.value)}
                                                placeholder="Enter organization"
                                            />
                                        </Field>
                                    </div>
                                </IncomingFormSection>
                            </div>

                            <div className="incoming-capture-stack">
                                <IncomingFormSection title="Dates" icon={<CalendarDays aria-hidden="true" />}>
                                    <div className="incoming-section-grid incoming-date-grid">
                                        <Field label="Letter date">
                                            <input
                                                className="input"
                                                type="date"
                                                value={form.data.letter_date}
                                                onChange={(event) => form.setData('letter_date', event.target.value)}
                                            />
                                        </Field>
                                        <Field label="Date received" required>
                                            <input
                                                className="input"
                                                type="date"
                                                value={form.data.received_date}
                                                onChange={(event) => form.setData('received_date', event.target.value)}
                                            />
                                        </Field>
                                        {features.receipt_method && (
                                            <Field label="Receipt method" wide>
                                                <select
                                                    className="select"
                                                    value={form.data.receipt_method}
                                                    onChange={(event) => form.setData('receipt_method', event.target.value)}
                                                >
                                                    <option value="hand">Hand delivery</option>
                                                    <option value="courier">Courier</option>
                                                    <option value="email">Email</option>
                                                    <option value="post">Post</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </Field>
                                        )}
                                    </div>
                                </IncomingFormSection>
                            </div>
                        </div>

                        <IncomingFormSection title="Subject" icon={<FileText aria-hidden="true" />} className="incoming-subject-section">
                            <div className="incoming-section-grid">
                                <Field label="Subject / Title" required wide>
                                    <input
                                        className="input"
                                        value={form.data.subject}
                                        onChange={(event) => form.setData('subject', event.target.value)}
                                        placeholder="Brief summary of the correspondence"
                                    />
                                </Field>
                                <div className="mail-field-wide">
                                    <MailDuplicateSuggestions
                                        input={{
                                            subject: form.data.subject,
                                            sender_name: form.data.sender_name,
                                            recipient_name: form.data.recipient_name,
                                            correspondence_reference: form.data.correspondence_reference,
                                            mail_date: form.data.received_date,
                                        }}
                                    />
                                </div>
                                {renderCaptureMetadataFields()}
                            </div>
                        </IncomingFormSection>

                        <IncomingFormSection title="To" icon={<Inbox aria-hidden="true" />}>
                            <div className="incoming-section-grid">
                                <Field label="Receipt type" wide>
                                    <select
                                        className="select"
                                        value={form.data.copied_for_information ? 'copied' : 'addressed'}
                                        onChange={(event) => form.setData('copied_for_information', event.target.value === 'copied')}
                                    >
                                        <option value="addressed">Addressed to recipient</option>
                                        <option value="copied">Copied for information only</option>
                                    </select>
                                </Field>
                                <Field label="To" required wide={form.data.destination_type !== 'internal'}>
                                    <select
                                        className="select"
                                        value={form.data.destination_type}
                                        onChange={(event) => setIncomingDestinationType(event.target.value as 'internal' | 'external')}
                                    >
                                        <option value="internal">Internal Ministry</option>
                                        <option value="external">External Source</option>
                                    </select>
                                    {form.errors.destination_type && <small className="field-error">{form.errors.destination_type}</small>}
                                </Field>
                                {form.data.destination_type === 'internal' ? (
                                    <>
                                        <Field label="Department/Officer" required>
                                            <select
                                                className="select"
                                                value={form.data.destination_directory_type}
                                                onChange={(event) => setDestinationSelection(event.target.value as MailPartySelection)}
                                            >
                                                <option value="shorthand">Officer Title</option>
                                                <option value="staff">Officer Name</option>
                                            </select>
                                            {form.errors.destination_directory_type && (
                                                <small className="field-error">{form.errors.destination_directory_type}</small>
                                            )}
                                        </Field>

                                        {form.data.destination_directory_type === 'staff' ? (
                                            <RecipientPicker
                                                selected={destinationStaff}
                                                onSelect={selectDestinationStaff}
                                                searchRoute={route('mail.party-search')}
                                                allowGroups={false}
                                                compactSelected
                                                label="Officer Name"
                                                placeholder="Search officer name, title, or department"
                                                error={form.errors.recipient_staff_user_id || form.errors.recipient_name}
                                            />
                                        ) : (
                                            <div className="incoming-directory-field mail-field-wide">
                                                <AnnotationTitlePicker
                                                    label="Officer Title"
                                                    selected={destinationTitle}
                                                    onSelect={selectDestinationTitle}
                                                    placeholder="Search officer title or shorthand"
                                                    error={form.errors.recipient_annotation_title_id || form.errors.recipient_name}
                                                />
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    <Field label="Individual Name" required wide>
                                        <input
                                            className="input"
                                            value={form.data.recipient_name}
                                            onChange={(event) => form.setData('recipient_name', event.target.value)}
                                            placeholder="Enter individual name"
                                        />
                                        {form.errors.recipient_name && <small className="field-error">{form.errors.recipient_name}</small>}
                                    </Field>
                                )}
                            </div>
                        </IncomingFormSection>

                        <div className="incoming-capture-columns incoming-bottom-row">
                            <IncomingFormSection
                                title="Detailed Description"
                                icon={<FileText aria-hidden="true" />}
                                className="incoming-description-section"
                            >
                                <div className="incoming-section-grid">
                                    <Field label="Description" wide>
                                        <textarea
                                            className="textarea"
                                            rows={5}
                                            value={form.data.details}
                                            onChange={(event) => form.setData('details', event.target.value)}
                                            placeholder="Additional context, instructions, or processing notes"
                                        />
                                    </Field>
                                </div>
                            </IncomingFormSection>

                            <IncomingFormSection title="Attachments" icon={<Paperclip aria-hidden="true" />} className="incoming-attachments-section">
                                <label className="incoming-attachment-picker">
                                    <UploadCloud aria-hidden="true" />
                                    <strong>
                                        {form.data.attachments.length > 0
                                            ? `${form.data.attachments.length} file(s) selected`
                                            : 'Choose correspondence files'}
                                    </strong>
                                    <span>PDF, Office documents, images, or videos · up to 5 files, 100 MB each</span>
                                    <input
                                        type="file"
                                        multiple
                                        accept=".pdf,.docx,.xlsx,.pptx,image/*,video/*"
                                        onChange={(event) => form.setData('attachments', Array.from(event.target.files ?? []))}
                                    />
                                </label>
                            </IncomingFormSection>
                        </div>
                    </div>
                ) : (
                    <div className="incoming-capture-layout outgoing-capture-layout">
                        <div className="incoming-capture-columns">
                            <IncomingFormSection title="From" icon={<Mail aria-hidden="true" />}>
                                <div className="incoming-section-grid">
                                    <Field label="Officer Name" required wide>
                                        <input
                                            className="input"
                                            value={form.data.sender_name}
                                            onChange={(event) => form.setData('sender_name', event.target.value)}
                                        />
                                    </Field>
                                    <Field label="Ministry Department / Organization" wide>
                                        <input
                                            className="input"
                                            value={form.data.sender_organisation}
                                            onChange={(event) => form.setData('sender_organisation', event.target.value)}
                                        />
                                    </Field>
                                </div>
                            </IncomingFormSection>

                            <IncomingFormSection title="Dates" icon={<CalendarDays aria-hidden="true" />}>
                                <div className="incoming-section-grid incoming-date-grid">
                                    <Field label="Letter date">
                                        <input
                                            className="input"
                                            type="date"
                                            value={form.data.letter_date}
                                            onChange={(event) => form.setData('letter_date', event.target.value)}
                                        />
                                    </Field>
                                    <Field label="Date sent">
                                        <input
                                            className="input"
                                            type="date"
                                            value={form.data.sent_date}
                                            onChange={(event) => form.setData('sent_date', event.target.value)}
                                        />
                                    </Field>
                                </div>
                            </IncomingFormSection>
                        </div>

                        <IncomingFormSection title="Subject" icon={<FileText aria-hidden="true" />} className="incoming-subject-section">
                            <div className="incoming-section-grid">
                                <Field label="Subject / Title" required wide>
                                    <input
                                        className="input"
                                        value={form.data.subject}
                                        onChange={(event) => form.setData('subject', event.target.value)}
                                    />
                                </Field>
                                <div className="mail-field-wide">
                                    <MailDuplicateSuggestions
                                        input={{
                                            subject: form.data.subject,
                                            sender_name: form.data.sender_name,
                                            recipient_name: form.data.recipient_name,
                                            correspondence_reference: form.data.correspondence_reference,
                                            mail_date: form.data.sent_date,
                                        }}
                                    />
                                </div>
                                {renderCaptureMetadataFields()}
                            </div>
                        </IncomingFormSection>

                        <IncomingFormSection title="To" icon={<Send aria-hidden="true" />}>
                            <div className="incoming-section-grid">
                                <Field label="To" required>
                                    <select
                                        className="select"
                                        value={form.data.destination_type === 'external' ? 'external' : form.data.destination_directory_type}
                                        onChange={(event) => setDestinationSelection(event.target.value as MailPartySelection)}
                                    >
                                        <option value="staff">Officer Name</option>
                                        <option value="shorthand">Officer Title</option>
                                        <option value="external">External Source</option>
                                    </select>
                                    {(form.errors.destination_type || form.errors.destination_directory_type) && (
                                        <small className="field-error">
                                            {form.errors.destination_type || form.errors.destination_directory_type}
                                        </small>
                                    )}
                                </Field>

                                {form.data.destination_type === 'internal' ? (
                                    form.data.destination_directory_type === 'staff' ? (
                                        <RecipientPicker
                                            selected={destinationStaff}
                                            onSelect={selectDestinationStaff}
                                            searchRoute={route('mail.party-search')}
                                            allowGroups={false}
                                            compactSelected
                                            label="Officer Name"
                                            placeholder="Search officer name, title, or department"
                                            error={form.errors.recipient_staff_user_id || form.errors.recipient_name}
                                        />
                                    ) : (
                                        <div className="incoming-directory-field mail-field-wide">
                                            <AnnotationTitlePicker
                                                label="Officer Title"
                                                selected={destinationTitle}
                                                onSelect={selectDestinationTitle}
                                                placeholder="Search officer title or shorthand"
                                                error={form.errors.recipient_annotation_title_id || form.errors.recipient_name}
                                            />
                                        </div>
                                    )
                                ) : (
                                    <Field label="Individual Name" required wide>
                                        <input
                                            className="input"
                                            placeholder="Enter individual name"
                                            value={form.data.recipient_name}
                                            onChange={(event) =>
                                                form.setData((current) => ({
                                                    ...current,
                                                    recipient_name: event.target.value,
                                                    recipient_annotation_title_id: '',
                                                    recipient_staff_user_id: '',
                                                }))
                                            }
                                        />
                                        {form.errors.recipient_name && <small className="field-error">{form.errors.recipient_name}</small>}
                                    </Field>
                                )}
                            </div>
                        </IncomingFormSection>

                        {canAssignOutgoing && (
                            <IncomingFormSection title="Follow-up" icon={<UserRoundCheck aria-hidden="true" />}>
                                <div className="incoming-section-grid">
                                    <fieldset className="forward-purpose-options outgoing-follow-up-choice mail-field-wide">
                                        <legend>Follow-up requirement</legend>
                                        <label className={form.data.requires_follow_up ? 'selected' : ''}>
                                            <input
                                                type="radio"
                                                name="outgoing-follow-up"
                                                checked={form.data.requires_follow_up}
                                                onChange={() => setFollowUpRequired(true)}
                                            />
                                            <span>
                                                <strong>Assign follow-up</strong>
                                            </span>
                                        </label>
                                        <label className={!form.data.requires_follow_up ? 'selected' : ''}>
                                            <input
                                                type="radio"
                                                name="outgoing-follow-up"
                                                checked={!form.data.requires_follow_up}
                                                onChange={() => setFollowUpRequired(false)}
                                            />
                                            <span>
                                                <strong>No follow-up</strong>
                                            </span>
                                        </label>
                                    </fieldset>

                                    {form.data.requires_follow_up && (
                                        <>
                                            <RecipientPicker
                                                selected={responsibleOfficer}
                                                onSelect={selectResponsibleOfficer}
                                                allowGroups={false}
                                                compactSelected
                                                label="Responsible officer"
                                                placeholder="Search responsible officer"
                                                error={form.errors.assigned_to_user_id}
                                            />
                                            <RecipientPicker
                                                selected={null}
                                                onSelect={selectOutgoingCc}
                                                allowGroups={false}
                                                required={false}
                                                label="CC officers"
                                                placeholder="Search officer to copy"
                                                error={form.errors.cc_user_ids}
                                            />
                                            {outgoingCc.length > 0 && (
                                                <div className="selected-assignees cc-recipient-list mail-field-wide">
                                                    {outgoingCc.map((recipient) => (
                                                        <span key={recipient.id} className="selected-assignee">
                                                            <span>
                                                                <strong>{recipient.name}</strong>
                                                                <small>{recipient.title || 'Staff member'} · CC</small>
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => removeOutgoingCc(recipient.id)}
                                                                aria-label={'Remove ' + recipient.name + ' from CC'}
                                                            >
                                                                <Trash2 aria-hidden="true" />
                                                            </button>
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                            <Field label="Due date">
                                                <input
                                                    className="input"
                                                    type="date"
                                                    min={today}
                                                    value={form.data.due_date}
                                                    onChange={(event) => form.setData('due_date', event.target.value)}
                                                />
                                            </Field>
                                            <Field label="Assignment instructions" required wide>
                                                <textarea
                                                    className="textarea"
                                                    rows={4}
                                                    value={form.data.instructions}
                                                    onChange={(event) => form.setData('instructions', event.target.value)}
                                                />
                                            </Field>
                                        </>
                                    )}
                                </div>
                            </IncomingFormSection>
                        )}

                        <div className="incoming-capture-columns incoming-bottom-row">
                            <IncomingFormSection
                                title="Detailed Description"
                                icon={<FileText aria-hidden="true" />}
                                className="incoming-description-section"
                            >
                                <div className="incoming-section-grid">
                                    <Field label="Description" wide>
                                        <textarea
                                            className="textarea"
                                            rows={5}
                                            value={form.data.details}
                                            onChange={(event) => form.setData('details', event.target.value)}
                                            placeholder="Additional context or processing notes"
                                        />
                                    </Field>
                                </div>
                            </IncomingFormSection>

                            <IncomingFormSection title="Attachments" icon={<Paperclip aria-hidden="true" />} className="incoming-attachments-section">
                                <label className="incoming-attachment-picker">
                                    <UploadCloud aria-hidden="true" />
                                    <strong>
                                        {form.data.attachments.length > 0
                                            ? form.data.attachments.length + ' file(s) selected'
                                            : 'Choose correspondence files'}
                                    </strong>
                                    <span>Up to 5 files, 100 MB each</span>
                                    <input
                                        type="file"
                                        multiple
                                        accept=".pdf,.docx,.xlsx,.pptx,image/*,video/*"
                                        onChange={(event) => form.setData('attachments', Array.from(event.target.files ?? []))}
                                    />
                                </label>
                            </IncomingFormSection>
                        </div>
                    </div>
                )}
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

function IncomingFormSection({
    title,
    icon,
    children,
    className = '',
}: {
    title: string;
    icon: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section className={`incoming-form-section ${className}`.trim()}>
            <div className="incoming-section-heading">
                <span aria-hidden="true">{icon}</span>
                <h3>{title}</h3>
            </div>
            {children}
        </section>
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

type DrawerAction = 'edit' | 'recipients' | 'assign' | 'assign_outgoing' | 'unassign' | 'update' | 'file' | 'reopen' | null;

type CorrespondenceAction = {
    label: string;
    icon: React.ReactNode;
    variant: 'primary' | 'ghost';
    onClick: () => void;
};

type CorrespondenceDrawerRecord = {
    eyebrow: string;
    reference?: string | null;
    title: string;
    status: string;
    financialYear?: string | null;
    dateLabel: string;
    dateCaption: string;
    details?: string | null;
    statusSubtitle: string;
};

type CorrespondenceStatusFact = {
    label: string;
    value: React.ReactNode;
};

type CorrespondenceField = {
    label: string;
    value: React.ReactNode;
    span?: 'half' | 'full';
    emphasis?: boolean;
};

type CorrespondenceAttachment = {
    id: number | string;
    name: string;
    url: string;
    size: string;
    source: MailAttachment;
};

type CorrespondenceTimelineItem = {
    id: string;
    label: string;
    title: React.ReactNode;
    sub?: React.ReactNode;
    meta?: React.ReactNode;
    icon: React.ReactNode;
    badge?: React.ReactNode;
    done: boolean;
    action?: React.ReactNode;
};

const correspondenceStatusTones: Record<string, 'amber' | 'green' | 'blue' | 'neutral'> = {
    withdrawn: 'amber',
    pending: 'amber',
    awaiting_review: 'amber',
    under_review: 'amber',
    active: 'green',
    assigned: 'green',
    completed: 'green',
    closed: 'green',
    filed: 'green',
    incoming: 'blue',
    received: 'blue',
    registered: 'blue',
};

function statusToneFor(mail: MailDetail): 'amber' | 'green' | 'blue' | 'neutral' {
    const statusKey = (mail.lifecycle_status || mail.status_value || mail.status).toLowerCase().replaceAll(' ', '_');
    return correspondenceStatusTones[statusKey] ?? 'neutral';
}

function buildCorrespondenceFields(mail: MailDetail, features: Props['mailFeatures']): CorrespondenceField[] {
    const incoming = mail.direction === 'incoming';
    const fields: CorrespondenceField[] = [
        {
            label: incoming ? 'Sender' : 'Prepared by',
            value: [mail.sender_name, mail.sender_organisation].filter(Boolean).join(' · '),
            emphasis: true,
        },
        { label: 'Addressee', value: mail.recipient_name, emphasis: true },
        { label: 'Letter date', value: mail.letter_date_label },
        { label: 'Entered by', value: [mail.captured_by, mail.captured_at_label].filter(Boolean).join(' · ') },
        { label: 'Handled by', value: mail.last_processed_by || mail.captured_by || 'Not yet assigned' },
        { label: incoming ? 'Date received' : 'Date sent', value: mail.mail_date_label },
    ];

    if (features.correspondence_reference) fields.push({ label: 'Sender reference', value: mail.correspondence_reference || '—' });
    if (features.register_number) fields.push({ label: 'Register number', value: mail.register_number });
    if (features.registry) {
        fields.push({
            label: 'Office / department',
            value: [mail.office_name, mail.department_name !== mail.office_name ? mail.department_name : null].filter(Boolean).join(' · '),
        });
    }
    if (features.registry_file_number && mail.registry_file_number) {
        fields.push({ label: 'Registry file number', value: mail.registry_file_number });
    }
    if (mail.prepared_on_behalf_of) fields.push({ label: 'Prepared on behalf of', value: mail.prepared_on_behalf_of });
    if (mail.dispatch_reference) {
        fields.push({ label: 'Dispatch', value: [mail.dispatch_method || 'Recorded', mail.dispatch_reference].join(' · ') });
    }

    fields.push({
        label: 'Mail type',
        value: [mail.record_kind, features.receipt_method ? mail.receipt_method : null].filter(Boolean).join(' · '),
        span: 'full',
    });

    return fields;
}

function MailDetailPanel({ mail, props, onClose }: { mail: MailDetail; props: Props; onClose: () => void }) {
    const [preview, setPreview] = useState<MailAttachment | null>(null);
    const [action, setAction] = useState<DrawerAction>(null);
    const [recipientToRemove, setRecipientToRemove] = useState<CorrespondenceRecipientInfo | null>(null);
    const [attachmentAction, setAttachmentAction] = useState<{ attachment: MailAttachment; mode: 'replace' | 'remove' } | null>(null);
    const assignment = mail.assignment;
    const canUnassign = mail.can_unassign && assignment !== null && !assignment.is_withdrawn && assignment.active_assignees.length > 0;
    const isWithdrawnMail = mail.lifecycle_status === 'withdrawn' || assignment?.is_withdrawn === true;
    const canRecoverAssignment = mail.direction === 'outgoing' ? mail.can_assign_outgoing : mail.can_assign;
    // Legacy records without a correspondence lifecycle report their register
    // status here, so both vocabularies count as "still awaiting forwarding".
    const isAwaitingForwarding = ['incoming', 'under_review', 'received', 'registered', 'awaiting_review'].includes(mail.lifecycle_status);
    const isFiled = mail.lifecycle_status === 'filed';
    const statusTone = statusToneFor(mail);
    const record: CorrespondenceDrawerRecord = {
        eyebrow: mail.record_kind,
        reference: props.mailFeatures.register_number ? mail.register_number : null,
        title: mail.subject,
        status: mail.status,
        financialYear: mail.financial_year,
        dateLabel: mail.mail_date_label,
        dateCaption: mail.direction === 'incoming' ? 'Date received' : 'Date sent',
        details: mail.details,
        statusSubtitle: mail.direction === 'outgoing' ? `${assignment?.status ?? 'Unassigned'} assignment` : 'Awaiting correspondence processing',
    };
    const detailFields = buildCorrespondenceFields(mail, props.mailFeatures);
    const statusFacts: CorrespondenceStatusFact[] = [];
    if (props.mailFeatures.priority) {
        statusFacts.push({ label: 'Priority', value: <span className={`badge ${mail.priority_class}`}>{mail.priority}</span> });
    }
    if (props.mailFeatures.confidentiality) statusFacts.push({ label: 'Confidentiality', value: mail.confidentiality });
    if (!isWithdrawnMail) statusFacts.push({ label: 'Financial year', value: record.financialYear || '—' });
    const attachments: CorrespondenceAttachment[] = mail.attachments.map((attachment) => ({
        id: attachment.id,
        name: attachment.filename,
        url: attachment.download_url,
        size: attachment.size_label,
        source: attachment,
    }));
    const actions: CorrespondenceAction[] = [];

    if (mail.can_assign && (isAwaitingForwarding || isFiled)) {
        actions.push({ label: 'Forward mail', icon: <Forward aria-hidden="true" />, variant: 'primary', onClick: () => setAction('assign') });
    }
    if (mail.can_file && isAwaitingForwarding && !isWithdrawnMail) {
        actions.push({ label: 'File correspondence', icon: <Archive aria-hidden="true" />, variant: 'ghost', onClick: () => setAction('file') });
    }
    if (mail.can_reopen && isFiled) {
        actions.push({ label: 'Reopen', icon: <FolderOpen aria-hidden="true" />, variant: 'ghost', onClick: () => setAction('reopen') });
    }
    if (mail.can_assign && !isAwaitingForwarding && !isFiled && !isWithdrawnMail) {
        actions.push({
            label: 'Manage forwarding',
            icon: <Workflow aria-hidden="true" />,
            variant: 'primary',
            onClick: () => setAction('recipients'),
        });
    }
    if (mail.can_assign_outgoing && !isWithdrawnMail) {
        actions.push({
            label: 'Create assignment',
            icon: <UserRoundCheck aria-hidden="true" />,
            variant: 'primary',
            onClick: () => setAction('assign_outgoing'),
        });
    }
    if (mail.can_participate) {
        actions.push({
            label: 'Add note or response',
            icon: <MessageSquarePlus aria-hidden="true" />,
            variant: 'ghost',
            onClick: () => setAction('update'),
        });
    }

    const normalizedActions = actions.map((drawerAction, index) => ({
        ...drawerAction,
        variant: index === 0 ? ('primary' as const) : ('ghost' as const),
    }));
    const hasUtilities = Boolean(mail.id) || assignment !== null || mail.can_edit || canUnassign;

    return (
        <Slideover
            size="wide"
            className="correspondence-drawer"
            onClose={onClose}
            header={
                <div className="task-view-heading forwarded-drawer-heading">
                    <div className="task-view-eyebrow">
                        <span>{record.eyebrow}</span>
                        {record.reference && <span>{record.reference}</span>}
                    </div>
                    <h2>{record.title}</h2>
                    <div className="forwarded-drawer-meta" aria-label="Correspondence summary">
                        <span className={`badge correspondence-status-badge tone-${statusTone}`}>{record.status}</span>
                        {props.mailFeatures.priority && <span className={`badge ${mail.priority_class}`}>{mail.priority}</span>}
                        {props.mailFeatures.confidentiality && <span className="badge muted">{mail.confidentiality}</span>}
                        {!isWithdrawnMail && (
                            <span className="forwarded-meta-item">
                                Financial year <strong>{record.financialYear || '—'}</strong>
                            </span>
                        )}
                        <span className="forwarded-meta-item">
                            <CalendarDays aria-hidden="true" /> {record.dateCaption} <strong>{record.dateLabel}</strong>
                        </span>
                    </div>
                </div>
            }
        >
            {(normalizedActions.length > 0 || hasUtilities) && (
                <div
                    className="mail-detail-actions correspondence-reveal correspondence-reveal-toolbar"
                    role="toolbar"
                    aria-label="Correspondence actions"
                >
                    <div className="mail-action-primary">
                        {normalizedActions.map((drawerAction) => (
                            <button
                                key={drawerAction.label}
                                type="button"
                                className={`btn ${drawerAction.variant === 'primary' ? 'btn-primary' : 'btn-ghost'}`}
                                onClick={drawerAction.onClick}
                            >
                                {drawerAction.icon} {drawerAction.label}
                            </button>
                        ))}
                    </div>
                    <div className="mail-action-utilities">
                        <a
                            className="btn btn-ghost mail-action-icon"
                            href={route('mail.print', mail.id)}
                            target="_blank"
                            rel="noreferrer"
                            aria-label="Print correspondence"
                            title="Print correspondence"
                        >
                            <Printer aria-hidden="true" />
                        </a>
                        {assignment !== null && !assignment.is_withdrawn && (
                            <Link className="btn btn-ghost" href={assignment.url}>
                                <ArrowRight aria-hidden="true" /> Open assignment
                            </Link>
                        )}
                        {mail.can_edit && (
                            <button
                                type="button"
                                className="btn btn-ghost mail-action-icon"
                                onClick={() => setAction('edit')}
                                aria-label={mail.record_kind === 'Outgoing / Forwarded' ? 'Edit forwarded mail' : 'Edit mail details'}
                                title={mail.record_kind === 'Outgoing / Forwarded' ? 'Edit forwarded mail' : 'Edit mail details'}
                            >
                                <Pencil aria-hidden="true" />
                            </button>
                        )}
                        {canUnassign && (
                            <button type="button" className="btn btn-ghost danger-button" onClick={() => setAction('unassign')}>
                                <UserMinus aria-hidden="true" /> Unassign
                            </button>
                        )}
                    </div>
                </div>
            )}

            {isWithdrawnMail && (canRecoverAssignment || mail.can_file) && (
                <section className="withdrawn-mail-next-action" aria-labelledby="withdrawn-mail-next-action-title">
                    <span className="withdrawn-mail-next-action-icon" aria-hidden="true">
                        <UserMinus />
                    </span>
                    <div className="withdrawn-mail-next-action-copy">
                        <span className="forwarded-status-kicker">Next action required</span>
                        <strong id="withdrawn-mail-next-action-title">
                            {assignment?.is_withdrawn
                                ? 'This mail was withdrawn from its assigned officer'
                                : 'This mail was withdrawn from its forwarding recipient'}
                        </strong>
                    </div>
                    <div className="withdrawn-mail-next-action-buttons">
                        {canRecoverAssignment && (
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => setAction(mail.direction === 'outgoing' ? 'assign_outgoing' : 'assign')}
                            >
                                <UserRoundCheck aria-hidden="true" /> Assign to another officer
                            </button>
                        )}
                        {mail.can_file && (
                            <button type="button" className="btn btn-ghost" onClick={() => setAction('file')}>
                                <Archive aria-hidden="true" /> File correspondence
                            </button>
                        )}
                    </div>
                </section>
            )}

            {!mail.can_assign && (isAwaitingForwarding || isFiled) && mail.forward_block_reason && (
                <p className="mail-modal-hint" role="note">
                    <ShieldCheck aria-hidden="true" /> Forwarding unavailable: {mail.forward_block_reason}
                </p>
            )}

            <div className="mail-detail-sections">
                {mail.filing && isFiled && (
                    <section className="card mail-section" aria-label="Filing details">
                        <SectionHeading icon={<Archive aria-hidden="true" />} title="Filed correspondence" />
                        <dl className="mail-info-grid">
                            <InfoItem label="Filed by">{mail.filing.filed_by}</InfoItem>
                            <InfoItem label="Filed on">{mail.filing.filed_at_label ?? '—'}</InfoItem>
                            <InfoItem label="Filing office">{mail.filing.office}</InfoItem>
                            <InfoItem label="Category">{mail.filing.category ?? '—'}</InfoItem>
                            {mail.filing.note && <InfoItem label="Filing note">{mail.filing.note}</InfoItem>}
                        </dl>
                    </section>
                )}
                <div className="forwarded-drawer-overview">
                    <MailInformationSection fields={detailFields} details={record.details} />
                    <aside className="forwarded-drawer-sidebar correspondence-reveal correspondence-reveal-sidebar">
                        <MailStatusSummary record={record} facts={statusFacts} tone={statusTone} />
                        <AttachmentsSection
                            attachments={attachments}
                            onPreview={setPreview}
                            onManage={mail.can_edit ? setAttachmentAction : undefined}
                            compact
                        />
                    </aside>
                </div>
                {(mail.primary_recipients.length > 0 || mail.cc_recipients.length > 0) && (
                    <RecipientsSection mail={mail} onRemove={mail.can_assign ? setRecipientToRemove : undefined} />
                )}
                {assignment !== null && <AssignmentSection assignment={assignment} onUnassign={canUnassign ? () => setAction('unassign') : null} />}
                {(mail.source_mail || mail.forwarded_records.length > 0) && (
                    <section className="card mail-section mail-related-workflow">
                        <SectionHeading icon={<Send aria-hidden="true" />} title="Related correspondence" />
                        <div className="mail-related-links">
                            {mail.source_mail && (
                                <Link href={mail.source_mail.url}>
                                    <Inbox aria-hidden="true" /> Original incoming
                                    {props.mailFeatures.register_number ? ` · ${mail.source_mail.register_number}` : ''}
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            )}
                            {mail.forwarded_records.map((record) => (
                                <Link key={record.id} href={record.url}>
                                    <Send aria-hidden="true" /> Outgoing forwarding
                                    {props.mailFeatures.register_number ? ` · ${record.register_number}` : ''}
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
            {action === 'edit' && <EditMailModal mail={mail} features={props.mailFeatures} onClose={() => setAction(null)} />}
            {action === 'recipients' && (
                <ManageRecipientsModal
                    mail={mail}
                    canUnassign={canUnassign}
                    onAdd={() => setAction('assign')}
                    onRemove={(recipient) => {
                        setAction(null);
                        setRecipientToRemove(recipient);
                    }}
                    onUnassign={() => setAction('unassign')}
                    onClose={() => setAction(null)}
                />
            )}
            {action === 'assign' && <AssignMailModal mail={mail} props={props} onClose={() => setAction(null)} />}
            {action === 'file' && <FileMailModal mail={mail} features={props.mailFeatures} onClose={() => setAction(null)} />}
            {action === 'reopen' && <ReopenMailModal mail={mail} onClose={() => setAction(null)} />}
            {action === 'assign_outgoing' && <AssignOutgoingMailModal mail={mail} props={props} onClose={() => setAction(null)} />}
            {action === 'update' && <CorrespondenceUpdateModal mail={mail} onClose={() => setAction(null)} />}
            {action === 'unassign' && assignment !== null && (
                <UnassignMailModal mail={mail} assignment={assignment} onClose={() => setAction(null)} />
            )}
            {recipientToRemove && <RemoveRecipientModal mail={mail} recipient={recipientToRemove} onClose={() => setRecipientToRemove(null)} />}
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

function MailStatusSummary({
    record,
    facts,
    tone,
}: {
    record: CorrespondenceDrawerRecord;
    facts: CorrespondenceStatusFact[];
    tone: 'amber' | 'green' | 'blue' | 'neutral';
}) {
    return (
        <section className={`card mail-section forwarded-status-summary tone-${tone}`} aria-label="Correspondence summary">
            <span className="forwarded-status-kicker">Current status</span>
            <div className="forwarded-status-main">
                <span className="forwarded-status-icon" aria-hidden="true">
                    <AlertCircle />
                </span>
                <div>
                    <strong>{record.status}</strong>
                    <small>{record.statusSubtitle}</small>
                </div>
            </div>
            <div className="forwarded-status-facts">
                {facts.map((fact) => (
                    <span key={fact.label}>
                        <small>{fact.label}</small>
                        <strong>{fact.value === null || fact.value === undefined || fact.value === '' ? '—' : fact.value}</strong>
                    </span>
                ))}
            </div>
        </section>
    );
}

function RecipientsSection({ mail, onRemove }: { mail: MailDetail; onRemove?: (recipient: CorrespondenceRecipientInfo) => void }) {
    const destinationCount = mail.primary_recipients.length + mail.cc_recipients.length;
    const timeline: CorrespondenceTimelineItem[] = [
        {
            id: 'origin',
            label: 'Prepared by / originating office',
            title: [mail.sender_name, mail.sender_organisation].filter(Boolean).join(' · '),
            icon: <Building2 />,
            done: true,
        },
    ];

    if (mail.assignment) {
        timeline.push({
            id: `assignment-${mail.assignment.task_id}`,
            label: 'Forwarded by',
            title: mail.assignment.assigned_by,
            meta: mail.assignment.assigned_at_label && (
                <time className="movement-time">
                    <CalendarDays aria-hidden="true" /> {mail.assignment.assigned_at_label}
                </time>
            ),
            icon: <Forward />,
            done: true,
        });
    }

    mail.primary_recipients.forEach((recipient, index) => {
        const isCurrent = index === mail.primary_recipients.length - 1;
        timeline.push({
            id: `primary-${recipient.id}`,
            label: isCurrent ? 'Current officer / office' : 'Handling officer / office',
            title: recipient.name,
            sub: recipient.title && !recipient.name.includes(recipient.title) ? recipient.title : undefined,
            meta: recipient.due_date_label ? <em>Due {recipient.due_date_label}</em> : undefined,
            icon: <UserRoundCheck />,
            badge: (
                <span className={`badge ${recipient.purpose === 'action_required' ? 'st-assigned' : 'muted'}`}>
                    {recipient.purpose === 'action_required' ? 'Action required' : 'Information only'}
                </span>
            ),
            done: !isCurrent,
            action:
                onRemove && recipient.task_id == null ? (
                    <button type="button" className="btn btn-ghost danger-button movement-remove" onClick={() => onRemove(recipient)}>
                        <UserMinus aria-hidden="true" /> Remove
                    </button>
                ) : undefined,
        });
    });

    mail.cc_recipients.forEach((recipient) => {
        timeline.push({
            id: `copy-${recipient.id}`,
            label: 'Copied for information',
            title: recipient.name,
            sub: recipient.title,
            icon: <Eye />,
            badge: <span className="badge info">Information only</span>,
            done: true,
            action: onRemove ? (
                <button type="button" className="btn btn-ghost danger-button movement-remove" onClick={() => onRemove(recipient)}>
                    <UserMinus aria-hidden="true" /> Remove
                </button>
            ) : undefined,
        });
    });

    return (
        <section className="card mail-section correspondence-recipient-section movement-of-mail-section">
            <SectionHeading
                icon={<Workflow aria-hidden="true" />}
                title="Movement of mail"
                aside={<span className="forwarded-destination-count">{destinationCount} total</span>}
            />
            <CorrespondenceTimeline items={timeline} />
        </section>
    );
}

function CorrespondenceTimeline({ items }: { items: CorrespondenceTimelineItem[] }) {
    return (
        <div className="correspondence-movement-timeline">
            {items.map((item) => (
                <article key={item.id} className={`movement-node ${item.done ? 'done' : 'current'}`}>
                    <span className="movement-node-icon" aria-hidden="true">
                        {item.icon}
                    </span>
                    <div className="movement-card">
                        <div className="movement-card-heading">
                            <span>
                                <span className="movement-label">{item.label}</span>
                                <strong>{item.title || '—'}</strong>
                            </span>
                            {item.badge}
                        </div>
                        {item.sub && <small>{item.sub}</small>}
                        {item.meta}
                        {item.action}
                    </div>
                </article>
            ))}
        </div>
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

function InfoItem({
    label,
    children,
    emphasis = false,
    span = 'half',
}: {
    label: string;
    children: React.ReactNode;
    emphasis?: boolean;
    span?: 'half' | 'full';
}) {
    return (
        <div className={`mail-info-item ${emphasis ? 'mail-info-item-emphasis' : ''} ${span === 'full' ? 'mail-info-item-full' : ''}`}>
            <dt>{label}</dt>
            <dd>{children === null || children === undefined || children === '' ? '—' : children}</dd>
        </div>
    );
}

function MailInformationSection({ fields, details }: { fields: CorrespondenceField[]; details?: string | null }) {
    return (
        <section className="card mail-section mail-correspondence-details correspondence-reveal correspondence-reveal-details">
            <SectionHeading icon={<Info aria-hidden="true" />} title="Correspondence details" />
            <dl className="mail-info-grid">
                {fields.map((field) => (
                    <InfoItem key={field.label} label={field.label} emphasis={field.emphasis} span={field.span}>
                        {field.value}
                    </InfoItem>
                ))}
            </dl>
            {details && (
                <div className="mail-details-text">
                    <span>Details</span>
                    <p>{details}</p>
                </div>
            )}
        </section>
    );
}

function AssignmentSection({ assignment, onUnassign }: { assignment: AssignmentInfo; onUnassign: (() => void) | null }) {
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
            <dl className="mail-info-grid">
                <InfoItem label="Assigned officer">{officerNames}</InfoItem>
                <InfoItem label="Assigning officer">{assignment.assigned_by}</InfoItem>
                <InfoItem label="Assignment date">{assignment.assigned_at_label ?? '—'}</InfoItem>
                <InfoItem label="Assignment priority">
                    <span className={`badge ${assignment.priority_class}`}>{assignment.priority}</span>
                </InfoItem>
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
    attachments,
    onPreview,
    onManage,
    compact = false,
}: {
    attachments: CorrespondenceAttachment[];
    onPreview: (file: MailAttachment) => void;
    onManage?: (action: { attachment: MailAttachment; mode: 'replace' | 'remove' }) => void;
    compact?: boolean;
}) {
    return (
        <section className={`card mail-section ${compact ? 'mail-attachments-compact' : ''}`}>
            <header className="drawer-attachments-heading">
                <span>Attachments ({attachments.length})</span>
            </header>
            {attachments.length === 0 ? (
                <div className="mail-attachment-empty">
                    <span aria-hidden="true">
                        <Paperclip />
                    </span>
                    <p>No files were attached to this correspondence.</p>
                </div>
            ) : (
                <div className="mail-attachment-list">
                    {attachments.map((file) => {
                        const source = file.source;
                        return (
                            <article key={file.id}>
                                <span>
                                    <FileText aria-hidden="true" />
                                </span>
                                <div>
                                    <a href={file.url} className="mail-attachment-name">
                                        {file.name}
                                    </a>
                                    <small>
                                        {file.size} · {source.uploaded_by}
                                        {source.uploaded_at_label ? ` · ${source.uploaded_at_label}` : ''}
                                        {source.correspondence_attachment_id ? ` · Version ${source.version_number}` : ''}
                                    </small>
                                </div>
                                <div>
                                    {source.preview_url && (
                                        <button type="button" className="btn btn-ghost" onClick={() => onPreview(source)}>
                                            <Eye aria-hidden="true" /> Preview
                                        </button>
                                    )}
                                    {source.preview_url && (
                                        <a className="btn btn-ghost" href={source.preview_url} target="_blank" rel="noreferrer">
                                            <ExternalLink aria-hidden="true" /> Open
                                        </a>
                                    )}
                                    <a className="btn btn-ghost" href={file.url}>
                                        <Download aria-hidden="true" /> Download
                                    </a>
                                    {onManage && source.correspondence_attachment_id && (
                                        <button
                                            type="button"
                                            className="btn btn-ghost"
                                            onClick={() => onManage({ attachment: source, mode: 'replace' })}
                                        >
                                            <Pencil aria-hidden="true" /> Replace
                                        </button>
                                    )}
                                    {onManage && source.correspondence_attachment_id && (
                                        <button
                                            type="button"
                                            className="btn btn-ghost danger-button"
                                            onClick={() => onManage({ attachment: source, mode: 'remove' })}
                                        >
                                            <Trash2 aria-hidden="true" /> Remove
                                        </button>
                                    )}
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

function ActivitySection({ entries }: { entries: ActivityEntry[] }) {
    return (
        <section className="card mail-section mail-history-card">
            <SectionHeading
                icon={<MessageSquareText aria-hidden="true" />}
                title="Notes and instructions history"
                aside={<span className="forwarded-destination-count">{entries.length} total</span>}
            />
            {entries.length === 0 ? (
                <EmptyState>No correspondence messages have been recorded yet.</EmptyState>
            ) : (
                <div className="mail-history-list" role="list" aria-label="Correspondence messages in chronological order">
                    {entries.map((entry) => (
                        <article key={entry.id} role="listitem">
                            <span className="mail-history-node" aria-hidden="true">
                                <MessageSquareText />
                            </span>
                            <div className="mail-history-entry-card">
                                <div className="mail-history-meta">
                                    <div>
                                        <strong>{entry.author_name}</strong>
                                        <span className="mail-history-kind">Correspondence note</span>
                                        <div className="mail-history-author-context">
                                            <span>{entry.author_title}</span>
                                            <span>{entry.author_office}</span>
                                            {entry.recorded_at_label && <time>{entry.recorded_at_label}</time>}
                                        </div>
                                    </div>
                                </div>
                                <p className="mail-history-message">{entry.message}</p>
                                {(entry.origin_title || entry.recipient_title) && (
                                    <div className="annotation-routing">
                                        {entry.origin_title && (
                                            <span>
                                                <strong>From:</strong> {entry.origin_title}
                                            </span>
                                        )}
                                        {entry.recipient_title && (
                                            <span>
                                                <ArrowRight aria-hidden="true" /> <strong>To:</strong> {entry.recipient_title}
                                            </span>
                                        )}
                                    </div>
                                )}
                                {entry.attachments.length > 0 && (
                                    <div className="timeline-attachment-list">
                                        {entry.attachments.map((attachment) => (
                                            <a key={attachment.download_url} href={attachment.download_url}>
                                                <Paperclip aria-hidden="true" /> {attachment.filename}
                                            </a>
                                        ))}
                                    </div>
                                )}
                            </div>
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

function EditMailModal({ mail, features, onClose }: { mail: MailDetail; features: Props['mailFeatures']; onClose: () => void }) {
    const form = useForm({ ...mail.edit_values });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('mail.update', mail.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            title="Edit correspondence"
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
            <form id="mail-edit-form" onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <Field label="From" required>
                        <input className="input" value={form.data.sender_name} onChange={(e) => form.setData('sender_name', e.target.value)} />
                    </Field>
                    <Field label="Ministry Department / Organization">
                        <input
                            className="input"
                            value={form.data.sender_organisation}
                            onChange={(e) => form.setData('sender_organisation', e.target.value)}
                        />
                    </Field>
                    <Field label="To" required>
                        <input className="input" value={form.data.recipient_name} onChange={(e) => form.setData('recipient_name', e.target.value)} />
                    </Field>
                    {features.correspondence_reference && (
                        <Field label="Correspondence reference">
                            <input
                                className="input"
                                value={form.data.correspondence_reference}
                                onChange={(e) => form.setData('correspondence_reference', e.target.value)}
                            />
                        </Field>
                    )}
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
                    {mail.direction === 'incoming' && features.receipt_method && (
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
                    {features.priority && (
                        <Field label="Priority">
                            <select className="select" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </Field>
                    )}
                    {features.confidentiality && (
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
                    )}
                    {features.registry_file_number && (
                        <Field label="Registry file number">
                            <input
                                className="input"
                                value={form.data.registry_file_number}
                                onChange={(e) => form.setData('registry_file_number', e.target.value)}
                            />
                        </Field>
                    )}
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
            title="Manage forwarding"
            size="wide"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Close
                    </button>
                    <button type="button" className="btn btn-primary" onClick={onAdd}>
                        <Plus aria-hidden="true" /> Add forwarding
                    </button>
                </>
            }
        >
            <div className="recipient-management-list">
                {recipients.length === 0 && (
                    <EmptyState>No active forwarding destinations. Add a destination to forward this correspondence again.</EmptyState>
                )}
                {recipients.map((recipient) => {
                    const hasActiveTask = recipient.purpose === 'action_required' && recipient.task_id != null;
                    return (
                        <article key={`${recipient.recipientType}-${recipient.id}`}>
                            <span className={`recipient-type-mark ${recipient.recipientType === 'CC' ? 'cc' : ''}`}>{recipient.recipientType}</span>
                            <div>
                                <strong>{recipient.name}</strong>
                                <small>
                                    {recipient.title || 'External or office recipient'} ·{' '}
                                    {recipient.purpose === 'action_required' ? 'Action required' : 'Information only'}
                                </small>
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

function RemoveRecipientModal({ mail, recipient, onClose }: { mail: MailDetail; recipient: CorrespondenceRecipientInfo; onClose: () => void }) {
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
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="remove-correspondence-recipient"
                        className="btn btn-danger"
                        disabled={form.processing || form.data.reason.trim().length < 5}
                    >
                        <UserMinus aria-hidden="true" /> {form.processing ? 'Removing…' : 'Remove recipient'}
                    </button>
                </>
            }
        >
            <form id="remove-correspondence-recipient" onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                <p className="mail-modal-hint">
                    Remove <strong>{recipient.name}</strong> from active correspondence access? Their original recipient entry remains in the audit
                    history.
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
                    <button
                        type="submit"
                        form="correspondence-update-form"
                        className="btn btn-primary"
                        disabled={form.processing || !form.data.body.trim()}
                    >
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
                    <Field label="Note or response" required wide>
                        <textarea
                            className="textarea"
                            rows={7}
                            value={form.data.body}
                            onChange={(event) => form.setData('body', event.target.value)}
                            placeholder="Write the note, response, recommendation, or decision…"
                        />
                    </Field>
                    <Field label="Attachments" wide>
                        <label className="assignment-file-picker">
                            <Paperclip aria-hidden="true" />
                            <span>
                                {form.data.attachments.length > 0 ? `${form.data.attachments.length} file(s) selected` : 'Choose optional files'}
                            </span>
                            <input type="file" multiple onChange={(event) => form.setData('attachments', Array.from(event.target.files ?? []))} />
                        </label>
                    </Field>
                </div>
            </form>
        </Modal>
    );
}

function FileMailModal({ mail, features, onClose }: { mail: MailDetail; features: Props['mailFeatures']; onClose: () => void }) {
    const form = useForm({
        filing_category: '',
        note: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (form.processing) {
            return;
        }
        form.post(route('mail.file', mail.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal
            title="File correspondence"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" form="mail-file-form" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? (
                            <>
                                <LoaderCircle className="spin" aria-hidden="true" /> Filing…
                            </>
                        ) : (
                            <>
                                <Archive aria-hidden="true" /> File correspondence
                            </>
                        )}
                    </button>
                </>
            }
        >
            <div className="forward-source-summary">
                {features.registry && (
                    <div>
                        <span>Filing office</span>
                        <strong>{mail.office_name}</strong>
                    </div>
                )}
                <div>
                    <span>Subject</span>
                    <strong>{mail.subject}</strong>
                </div>
            </div>
            <form id="mail-file-form" onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <Field label="File category or classification">
                        <input
                            className="input"
                            type="text"
                            maxLength={120}
                            value={form.data.filing_category}
                            onChange={(event) => form.setData('filing_category', event.target.value)}
                            placeholder="Category or classification"
                        />
                    </Field>
                    <Field label="Filing note or reason" wide>
                        <textarea
                            className="textarea"
                            rows={4}
                            value={form.data.note}
                            onChange={(event) => form.setData('note', event.target.value)}
                            placeholder={
                                mail.lifecycle_status === 'withdrawn' || mail.assignment?.is_withdrawn
                                    ? 'Explain why no reassignment or further action is required…'
                                    : 'Why is this correspondence being filed without action?'
                            }
                        />
                    </Field>
                </div>
            </form>
        </Modal>
    );
}

function ReopenMailModal({ mail, onClose }: { mail: MailDetail; onClose: () => void }) {
    const form = useForm({ note: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (form.processing) {
            return;
        }
        form.post(route('mail.reopen', mail.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal
            title="Reopen filed correspondence"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" form="mail-reopen-form" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? (
                            <>
                                <LoaderCircle className="spin" aria-hidden="true" /> Reopening…
                            </>
                        ) : (
                            <>
                                <FolderOpen aria-hidden="true" /> Reopen correspondence
                            </>
                        )}
                    </button>
                </>
            }
        >
            <form id="mail-reopen-form" onSubmit={submit}>
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <Field label="Reason for reopening" wide>
                        <textarea
                            className="textarea"
                            rows={4}
                            value={form.data.note}
                            onChange={(event) => form.setData('note', event.target.value)}
                            placeholder="Why is this correspondence being reopened?"
                        />
                    </Field>
                </div>
            </form>
        </Modal>
    );
}

function AssignOutgoingMailModal({ mail, props, onClose }: { mail: MailDetail; props: Props; onClose: () => void }) {
    const today = todayForDateInput();
    const form = useForm({
        assigned_to_user_id: '' as number | '',
        cc_user_ids: [] as number[],
        instructions: '',
        due_date: '',
        priority: mail.priority.toLowerCase(),
        attachments: [] as File[],
    });
    const [responsibleOfficer, setResponsibleOfficer] = useState<RecipientSuggestion | null>(null);
    const [ccOfficers, setCcOfficers] = useState<RecipientSuggestion[]>([]);

    const selectResponsible = (recipient: RecipientSuggestion | null) => {
        if (recipient !== null && ccOfficers.some((cc) => cc.id === recipient.id)) return;
        setResponsibleOfficer(recipient);
        form.setData('assigned_to_user_id', recipient?.id ?? '');
        form.clearErrors('assigned_to_user_id');
    };
    const selectCc = (recipient: RecipientSuggestion | null) => {
        if (
            recipient === null ||
            recipient.assignment_target_type !== 'individual' ||
            recipient.id === responsibleOfficer?.id ||
            ccOfficers.some((cc) => cc.id === recipient.id)
        )
            return;
        const selected = [...ccOfficers, recipient];
        setCcOfficers(selected);
        form.setData(
            'cc_user_ids',
            selected.map((cc) => cc.id),
        );
        form.clearErrors('cc_user_ids');
    };
    const removeCc = (recipientId: number) => {
        const selected = ccOfficers.filter((cc) => cc.id !== recipientId);
        setCcOfficers(selected);
        form.setData(
            'cc_user_ids',
            selected.map((cc) => cc.id),
        );
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('mail.assign-outgoing', mail.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            title="Create assignment from outgoing correspondence"
            size="wide"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="outgoing-assignment-form"
                        className="btn btn-primary"
                        disabled={form.processing || responsibleOfficer === null || form.data.instructions.trim() === ''}
                    >
                        {form.processing ? <LoaderCircle className="spin" aria-hidden="true" /> : <UserRoundCheck aria-hidden="true" />}
                        Create assignment
                    </button>
                </>
            }
        >
            <div className="outgoing-assignment-source">
                <Send aria-hidden="true" />
                <div>
                    {props.mailFeatures.register_number && <span>{mail.register_number}</span>}
                    <strong>{mail.subject}</strong>
                </div>
            </div>
            <form id="outgoing-assignment-form" onSubmit={submit} encType="multipart/form-data">
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <RecipientPicker
                        selected={responsibleOfficer}
                        onSelect={selectResponsible}
                        allowGroups={false}
                        label="Officer Name (Responsible)"
                        placeholder="Search officer name"
                        error={form.errors.assigned_to_user_id}
                    />
                    <RecipientPicker
                        selected={null}
                        onSelect={selectCc}
                        allowGroups={false}
                        required={false}
                        label="Officer Name (CC)"
                        placeholder="Search officer name"
                        error={form.errors.cc_user_ids}
                    />
                    {ccOfficers.length > 0 && (
                        <div className="selected-assignees cc-recipient-list mail-field-wide">
                            {ccOfficers.map((recipient) => (
                                <span key={recipient.id} className="selected-assignee">
                                    <span>
                                        <strong>{recipient.name}</strong>
                                        <small>{recipient.title || 'Staff member'} · CC — information only</small>
                                    </span>
                                    <button type="button" onClick={() => removeCc(recipient.id)} aria-label={`Remove ${recipient.name} from CC`}>
                                        <Trash2 aria-hidden="true" />
                                    </button>
                                </span>
                            ))}
                        </div>
                    )}
                    {props.mailFeatures.priority && (
                        <Field label="Priority">
                            <select className="select" value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)}>
                                {props.priorityOptions.map((priority) => (
                                    <option key={priority.value} value={priority.value}>
                                        {priority.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    <Field label="Due date">
                        <input
                            className="input"
                            type="date"
                            min={today}
                            value={form.data.due_date}
                            onChange={(event) => form.setData('due_date', event.target.value)}
                        />
                    </Field>
                    <Field label="Detailed Description" required wide>
                        <textarea
                            className="textarea"
                            rows={6}
                            value={form.data.instructions}
                            onChange={(event) => form.setData('instructions', event.target.value)}
                            placeholder="Describe the action required from the responsible officer."
                        />
                    </Field>
                    <Field label="Attachments" wide>
                        <label className="assignment-file-picker">
                            <Paperclip aria-hidden="true" />
                            <span>
                                {form.data.attachments.length > 0 ? `${form.data.attachments.length} file(s) selected` : 'Choose supporting files'}
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
    const confirm = useConfirm();
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
        origin_title_id: mail.forward_origin_title?.id ?? ('' as number | ''),
        recipient_title_id: '' as number | '',
        priority: 'medium',
        due_date: '',
        instructions: '',
        workstream_id: '',
        attachments: [] as File[],
    });
    const [recipients, setRecipients] = useState<RecipientSuggestion[]>([]);
    const [ccRecipients, setCcRecipients] = useState<RecipientSuggestion[]>([]);
    const [recipientTitle, setRecipientTitle] = useState<AnnotationTitleOption | null>(null);
    const [recipientLookupType, setRecipientLookupType] = useState<'title' | 'directory'>('title');
    const [ccExpanded, setCcExpanded] = useState(false);
    const [externalName, setExternalName] = useState('');
    const [externalOrganisation, setExternalOrganisation] = useState('');
    const [externalType, setExternalType] = useState<'to' | 'cc'>('to');
    const [externalExpanded, setExternalExpanded] = useState(false);
    const guardState = useRef({ dirty: false, processing: false, submitting: false });
    const formActions = useRef({ reset: form.reset, clearErrors: form.clearErrors });
    const confirmOpen = useRef(false);
    const bypassNextVisit = useRef(false);
    guardState.current.dirty = form.isDirty || externalName.trim() !== '' || externalOrganisation.trim() !== '';
    guardState.current.processing = form.processing;
    formActions.current = { reset: form.reset, clearErrors: form.clearErrors };

    const discardThen = useCallback(
        async (action: () => void) => {
            if (confirmOpen.current) return;
            confirmOpen.current = true;
            const discard = await confirm({
                title: 'Discard forwarding changes?',
                message: 'The recipients and forwarding details entered here have not been saved. You can continue editing or discard them.',
                confirmLabel: 'Discard changes',
                cancelLabel: 'Continue editing',
                variant: 'danger',
            });
            confirmOpen.current = false;
            if (!discard) return;

            guardState.current.dirty = false;
            formActions.current.reset();
            formActions.current.clearErrors();
            action();
        },
        [confirm],
    );

    const requestClose = () => {
        if (guardState.current.processing || guardState.current.submitting) return;
        if (!guardState.current.dirty) {
            onClose();
            return;
        }
        void discardThen(onClose);
    };

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            const state = guardState.current;
            if (!state.dirty || state.processing || state.submitting) return;
            event.preventDefault();
            event.returnValue = '';
        };
        const removeInertiaGuard = router.on('before', (event) => {
            if (isBackgroundInertiaVisit(event.detail.visit)) return;

            if (bypassNextVisit.current) {
                bypassNextVisit.current = false;
                return;
            }
            const state = guardState.current;
            if (!state.dirty || state.processing || state.submitting) return;

            event.preventDefault();
            const pendingVisit: PendingVisit = event.detail.visit;
            void discardThen(() => {
                const options = { ...pendingVisit };
                onClose();
                bypassNextVisit.current = true;
                router.visit(pendingVisit.url, options);
            });
        });
        window.addEventListener('beforeunload', warn);
        return () => {
            removeInertiaGuard();
            window.removeEventListener('beforeunload', warn);
        };
    }, [discardThen, onClose]);

    const addExternalRecipient = () => {
        const name = externalName.trim();
        if (!name) return;
        if (form.data.external_recipients.some((recipient) => recipient.name.trim().toLocaleLowerCase() === name.toLocaleLowerCase())) {
            form.setError('external_recipients', 'This individual has already been added.');
            return;
        }
        form.setData('external_recipients', [
            ...form.data.external_recipients,
            { name, organisation: externalOrganisation.trim(), recipient_type: externalType },
        ]);
        form.clearErrors('external_recipients', 'assigned_to_user_ids');
        setExternalName('');
        setExternalOrganisation('');
    };
    const removeExternalRecipient = (index: number) => {
        form.setData(
            'external_recipients',
            form.data.external_recipients.filter((_, current) => current !== index),
        );
        form.clearErrors('external_recipients');
    };
    const selectRecipient = (next: RecipientSuggestion | null) => {
        if (
            next === null ||
            recipients.some((recipient) => recipient.key === next.key) ||
            ccRecipients.some((recipient) => recipient.key === next.key)
        ) {
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
        form.setData(
            'cc_user_ids',
            selected.map((recipient) => recipient.id),
        );
        form.clearErrors('cc_user_ids');
    };
    const removeCcRecipient = (key: string) => {
        const selected = ccRecipients.filter((recipient) => recipient.key !== key);
        setCcRecipients(selected);
        form.setData(
            'cc_user_ids',
            selected.map((recipient) => recipient.id),
        );
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
    const changeRecipientLookupType = (type: 'title' | 'directory') => {
        setRecipientLookupType(type);
        if (type === 'title') {
            setRecipients([]);
            form.setData((current) => ({
                ...current,
                target_type: 'individual',
                department_id: '',
                organizational_unit_id: '',
                target_department_id: '',
                assigned_to_user_ids: [],
            }));
            form.clearErrors('assigned_to_user_ids', 'department_id', 'organizational_unit_id', 'target_department_id', 'target_type');
            return;
        }

        setRecipientTitle(null);
        form.setData('recipient_title_id', '');
        form.clearErrors('recipient_title_id');
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (form.processing || guardState.current.submitting) {
            return;
        }
        guardState.current.submitting = true;
        form.post(route('mail.assign', mail.id), {
            forceFormData: true,
            preserveScroll: true,
            // Success closes the forwarding interface and clears its entries;
            // the redirect back to the record shows the confirmation toast and
            // refreshed forwarding status. Validation errors keep it open so
            // the user can correct the problem and try again.
            onSuccess: () => {
                guardState.current.dirty = false;
                form.reset();
                form.clearErrors();
                setRecipients([]);
                setCcRecipients([]);
                setRecipientTitle(null);
                setRecipientLookupType('title');
                setCcExpanded(false);
                onClose();
            },
            onError: () => {
                guardState.current.submitting = false;
            },
            onCancel: () => {
                guardState.current.submitting = false;
            },
            onFinish: () => {
                guardState.current.submitting = false;
            },
        });
    };
    return (
        <Modal
            title="Forward correspondence"
            size="wide"
            className="incoming-capture-modal outgoing-capture-modal forward-correspondence-modal"
            onClose={requestClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={requestClose} disabled={form.processing}>
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="mail-assign-form"
                        className="btn btn-primary"
                        disabled={
                            form.processing ||
                            (recipients.length === 0 &&
                                recipientTitle === null &&
                                !form.data.external_recipients.some((recipient) => recipient.recipient_type === 'to'))
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
            <form id="mail-assign-form" onSubmit={submit} encType="multipart/form-data">
                <FormErrorSummary errors={form.errors} />
                <div className="incoming-capture-layout outgoing-capture-layout forward-capture-layout">
                    <div className="incoming-capture-columns">
                        <IncomingFormSection title="From" icon={<Mail aria-hidden="true" />}>
                            <div className="incoming-section-grid">
                                <div className="correspondence-subject-preview correspondence-source-preview mail-field-wide">
                                    <div>
                                        <strong>{mail.sender_name}</strong>
                                        {(mail.sender_organisation || mail.forward_origin_title) && (
                                            <small>{mail.forward_origin_title?.label ?? mail.sender_organisation}</small>
                                        )}
                                    </div>
                                    {mail.forward_origin_title && <span>{mail.forward_origin_title.shorthand}</span>}
                                </div>
                            </div>
                        </IncomingFormSection>

                        <IncomingFormSection title="Dates" icon={<CalendarDays aria-hidden="true" />}>
                            <div className="incoming-section-grid incoming-date-grid">
                                <Field label="Date forwarded" required wide>
                                    <input
                                        className="input"
                                        type="date"
                                        max={today}
                                        required
                                        value={form.data.forwarded_date}
                                        onChange={(event) => form.setData('forwarded_date', event.target.value)}
                                    />
                                </Field>
                            </div>
                        </IncomingFormSection>
                    </div>

                    <IncomingFormSection title="Subject" icon={<FileText aria-hidden="true" />} className="incoming-subject-section">
                        <div className="correspondence-subject-preview">
                            <strong>{mail.subject}</strong>
                            <span>{mail.register_number}</span>
                        </div>
                    </IncomingFormSection>

                    <IncomingFormSection title="To" icon={<Forward aria-hidden="true" />}>
                        <div className="incoming-section-grid">
                            <Field label="Internal recipient" wide>
                                <select
                                    className="select"
                                    value={recipientLookupType}
                                    onChange={(event) => changeRecipientLookupType(event.target.value as 'title' | 'directory')}
                                >
                                    <option value="title">Officer Title</option>
                                    <option value="directory">Department/Officer</option>
                                </select>
                            </Field>

                            {recipientLookupType === 'title' ? (
                                <div className="incoming-directory-field mail-field-wide">
                                    <AnnotationTitlePicker
                                        label="Officer Title"
                                        selected={recipientTitle}
                                        onSelect={(title) => {
                                            setRecipientTitle(title);
                                            form.setData('recipient_title_id', title?.id ?? '');
                                        }}
                                        placeholder="Search officer title or shorthand"
                                        error={form.errors.recipient_title_id}
                                    />
                                </div>
                            ) : (
                                <RecipientPicker
                                    mailId={mail.id}
                                    selected={null}
                                    onSelect={selectRecipient}
                                    label="Department/Officer"
                                    placeholder="Search officer name or department"
                                    required={false}
                                    error={
                                        form.errors.assigned_to_user_ids ||
                                        form.errors.department_id ||
                                        form.errors.organizational_unit_id ||
                                        form.errors.target_department_id ||
                                        form.errors.target_type
                                    }
                                />
                            )}
                            {recipientLookupType === 'directory' && recipients.length > 0 && (
                                <div className="selected-assignees mail-field-wide">
                                    {recipients.map((recipient) => (
                                        <span key={recipient.key} className="selected-assignee">
                                            <span>
                                                <strong>{recipient.name}</strong>
                                                <small>
                                                    {recipient.title || recipient.department || 'Staff member'} · {recipient.role}
                                                </small>
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => removeRecipient(recipient.key)}
                                                aria-label={'Remove ' + recipient.name}
                                            >
                                                <Trash2 aria-hidden="true" />
                                            </button>
                                        </span>
                                    ))}
                                </div>
                            )}

                            <fieldset className="forward-external-recipient mail-field-wide">
                                <legend>CC / For information</legend>
                                <button
                                    type="button"
                                    className="forward-external-toggle"
                                    aria-expanded={ccExpanded}
                                    aria-controls="forward-cc-fields"
                                    onClick={() => setCcExpanded((current) => !current)}
                                >
                                    <span>
                                        <UsersRound aria-hidden="true" />
                                        <span>
                                            <strong>
                                                {ccRecipients.length > 0 ? `${ccRecipients.length} person(s) copied` : 'Add CC recipient'}
                                            </strong>
                                        </span>
                                    </span>
                                    <ChevronDown className={ccExpanded ? 'expanded' : ''} aria-hidden="true" />
                                </button>
                                {ccExpanded && (
                                    <div id="forward-cc-fields" className="forward-optional-recipient-fields">
                                        <RecipientPicker
                                            mailId={mail.id}
                                            selected={null}
                                            onSelect={selectCcRecipient}
                                            label="Officer Name"
                                            placeholder="Search officer name"
                                            required={false}
                                            allowGroups={false}
                                            error={form.errors.cc_user_ids}
                                        />
                                    </div>
                                )}
                                {ccRecipients.length > 0 && (
                                    <div className="selected-assignees cc-recipient-list">
                                        {ccRecipients.map((recipient) => (
                                            <span key={recipient.key} className="selected-assignee">
                                                <span>
                                                    <strong>{recipient.name}</strong>
                                                    <small>{recipient.title || 'Staff member'} · CC</small>
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() => removeCcRecipient(recipient.key)}
                                                    aria-label={'Remove ' + recipient.name + ' from CC'}
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </fieldset>

                            <fieldset className="forward-external-recipient mail-field-wide">
                                <legend>External Source</legend>
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
                                            <strong>
                                                {form.data.external_recipients.length > 0
                                                    ? form.data.external_recipients.length + ' individual(s) added'
                                                    : 'Add individual'}
                                            </strong>
                                        </span>
                                    </span>
                                    <ChevronDown className={externalExpanded ? 'expanded' : ''} aria-hidden="true" />
                                </button>
                                {externalExpanded && (
                                    <div id="forward-external-fields" className="forward-external-grid">
                                        <label>
                                            <span>Individual Name</span>
                                            <input
                                                className="input"
                                                value={externalName}
                                                onChange={(event) => setExternalName(event.target.value)}
                                                placeholder="Institution or contact"
                                            />
                                        </label>
                                        <label>
                                            <span>Organization</span>
                                            <input
                                                className="input"
                                                value={externalOrganisation}
                                                onChange={(event) => setExternalOrganisation(event.target.value)}
                                            />
                                        </label>
                                        <label>
                                            <span>Recipient type</span>
                                            <select
                                                className="select"
                                                value={externalType}
                                                onChange={(event) => setExternalType(event.target.value as 'to' | 'cc')}
                                            >
                                                <option value="to">To / Primary</option>
                                                <option value="cc">CC / Information</option>
                                            </select>
                                        </label>
                                        <button
                                            type="button"
                                            className="btn btn-ghost"
                                            onClick={addExternalRecipient}
                                            disabled={!externalName.trim()}
                                        >
                                            <Plus aria-hidden="true" /> Add individual
                                        </button>
                                    </div>
                                )}
                                {form.data.external_recipients.length > 0 && (
                                    <div className="selected-assignees cc-recipient-list">
                                        {form.data.external_recipients.map((recipient, index) => (
                                            <span key={recipient.name + '-' + index} className="selected-assignee">
                                                <span>
                                                    <strong>{recipient.name}</strong>
                                                    <small>
                                                        {recipient.organisation || 'External'} ·{' '}
                                                        {recipient.recipient_type === 'to' ? 'Primary' : 'CC'}
                                                    </small>
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() => removeExternalRecipient(index)}
                                                    aria-label={'Remove ' + recipient.name}
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </fieldset>
                        </div>
                    </IncomingFormSection>

                    <IncomingFormSection title="Action" icon={<FileText aria-hidden="true" />}>
                        <div className="incoming-section-grid incoming-details-grid">
                            <fieldset className="forward-purpose-options mail-field-wide">
                                <legend>Forwarding purpose</legend>
                                <label className={form.data.action_required ? 'selected' : ''}>
                                    <input
                                        type="radio"
                                        name="forward-purpose"
                                        checked={form.data.action_required}
                                        onChange={() => form.setData('action_required', true)}
                                    />
                                    <span>
                                        <strong>Action required</strong>
                                    </span>
                                </label>
                                <label className={!form.data.action_required ? 'selected' : ''}>
                                    <input
                                        type="radio"
                                        name="forward-purpose"
                                        checked={!form.data.action_required}
                                        onChange={() => form.setData((current) => ({ ...current, action_required: false, due_date: '' }))}
                                    />
                                    <span>
                                        <strong>Information only</strong>
                                    </span>
                                </label>
                            </fieldset>

                            {props.mailFeatures.priority && (
                                <Field label="Priority">
                                    <select
                                        className="select"
                                        value={form.data.priority}
                                        onChange={(event) => form.setData('priority', event.target.value)}
                                    >
                                        {props.priorityOptions.map((priority) => (
                                            <option key={priority.value} value={priority.value}>
                                                {priority.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}
                            {props.mailFeatures.forwarding_due_date && form.data.action_required && (
                                <Field label="Due date">
                                    <input
                                        className="input"
                                        type="date"
                                        value={form.data.due_date}
                                        onChange={(event) => form.setData('due_date', event.target.value)}
                                    />
                                </Field>
                            )}
                            {props.mailFeatures.project_programme && (
                                <Field label="Project, programme or subject" wide>
                                    <select
                                        className="select"
                                        value={form.data.workstream_id}
                                        onChange={(event) => form.setData('workstream_id', event.target.value)}
                                    >
                                        <option value="">Not specified</option>
                                        {props.workstreamOptions.map((workstream) => (
                                            <option key={workstream.id} value={workstream.id}>
                                                {workstream.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}
                        </div>
                    </IncomingFormSection>

                    <div className="incoming-capture-columns incoming-bottom-row">
                        <IncomingFormSection
                            title="Detailed Description"
                            icon={<FileText aria-hidden="true" />}
                            className="incoming-description-section"
                        >
                            <div className="incoming-section-grid">
                                <Field label="Annotation / Instructions" wide>
                                    <textarea
                                        className="textarea"
                                        rows={6}
                                        value={form.data.instructions}
                                        onChange={(event) => form.setData('instructions', event.target.value)}
                                        placeholder="Originating officer's annotation or instructions"
                                    />
                                </Field>
                            </div>
                        </IncomingFormSection>

                        <IncomingFormSection title="Attachments" icon={<Paperclip aria-hidden="true" />} className="incoming-attachments-section">
                            <label className="incoming-attachment-picker">
                                <UploadCloud aria-hidden="true" />
                                <strong>
                                    {form.data.attachments.length > 0
                                        ? form.data.attachments.length + ' supporting file(s) selected'
                                        : 'Choose supporting files'}
                                </strong>
                                <input type="file" multiple onChange={(event) => form.setData('attachments', Array.from(event.target.files ?? []))} />
                            </label>
                        </IncomingFormSection>
                    </div>
                </div>
            </form>
        </Modal>
    );
}

function ManageAttachmentModal({ attachment, mode, onClose }: { attachment: MailAttachment; mode: 'replace' | 'remove'; onClose: () => void }) {
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
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
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
                    <strong>{attachment.filename}</strong> is version {attachment.version_number}. The current file and its metadata will remain
                    traceable after this action.
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
