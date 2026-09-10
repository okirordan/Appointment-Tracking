import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import RecipientPicker, { type RecipientSuggestion } from '@/components/ats/recipient-picker';
import { ArrowRight, Building2, LoaderCircle, MessageSquareText, Paperclip, Save } from '@/components/icons';
import { useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';

export interface DepartmentInteractionEntry {
    id: number;
    action_type: string;
    from: string;
    to: string;
    represented_office: string;
    recorded_by: string;
    recorded_by_title: string | null;
    entry_method: string;
    annotation: string | null;
    occurred_at_label: string | null;
    recorded_at_label: string | null;
    previous_status: string;
    new_status: string;
    responsible_officer: string | null;
    task_id: number | null;
    attachments: { filename: string; download_url: string }[];
}

export interface CorrespondenceActivityEntry {
    id: string;
    message: string;
    origin_title: string | null;
    recipient_title: string | null;
    author_name: string;
    author_title: string;
    author_office: string;
    kind?: string;
    occurred_at_label?: string | null;
    recorded_at_label: string | null;
    attachments: { filename: string; download_url: string }[];
}

function nowForDateTimeInput(): string {
    const now = new Date();
    const localDate = new Date(now.getTime() - now.getTimezoneOffset() * 60_000);

    return localDate.toISOString().slice(0, 16);
}

function todayForDateInput(): string {
    return nowForDateTimeInput().slice(0, 10);
}

function Field({
    label,
    children,
    required = false,
    wide = false,
    hint,
}: {
    label: string;
    children: ReactNode;
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

export function NotesAndInstructionsHistory({
    entries,
    canRecordDepartmentInteraction = false,
    onRecordDepartmentInteraction,
}: {
    entries: CorrespondenceActivityEntry[];
    canRecordDepartmentInteraction?: boolean;
    onRecordDepartmentInteraction?: () => void;
}) {
    return (
        <section className="card mail-section mail-history-card" aria-label="Notes and instructions history">
            <header className="mail-section-heading">
                <span className="mail-section-icon">
                    <MessageSquareText aria-hidden="true" />
                </span>
                <div>
                    <h3>Notes and instructions history</h3>
                </div>
                <div className="mail-section-aside">
                    <span className="forwarded-destination-count">{entries.length} total</span>
                </div>
            </header>
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
                                        <span className="mail-history-kind">{entry.kind ?? 'Correspondence note'}</span>
                                        <div className="mail-history-author-context">
                                            <span>{entry.author_title}</span>
                                            <span>{entry.author_office}</span>
                                            {entry.occurred_at_label && <time>Occurred {entry.occurred_at_label}</time>}
                                            {entry.recorded_at_label && <time>Recorded {entry.recorded_at_label}</time>}
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
            {canRecordDepartmentInteraction && onRecordDepartmentInteraction && (
                <div className="mail-history-record-action">
                    <button type="button" className="btn btn-primary" onClick={onRecordDepartmentInteraction}>
                        <Building2 aria-hidden="true" /> Add Correspondence
                    </button>
                </div>
            )}
        </section>
    );
}

export function DepartmentInteractionModal({ mailId, onClose }: { mailId: number; onClose: () => void }) {
    const [target, setTarget] = useState<RecipientSuggestion | null>(null);
    const form = useForm({
        direction: 'ps_to_department',
        organizational_unit_id: '',
        action_type: 'forwarded_for_action',
        annotation: '',
        occurred_at: nowForDateTimeInput(),
        status_after: 'action_required',
        responsible_user_id: '',
        due_date: '',
        confirm_duplicate: false as boolean,
        duplicate_confirmation: '',
        attachments: [] as File[],
    });
    const outboundActions = [
        ['forwarded_for_action', 'Forwarded for action'],
        ['forwarded_elsewhere', 'Forwarded elsewhere'],
        ['information_requested', 'Information requested'],
        ['clarification_requested', 'Clarification requested'],
    ];
    const returnActions = [
        ['returned_to_ps', 'Returned to PS Office'],
        ['referred_back', 'Referred back'],
        ['response_received', 'Response received'],
        ['action_completed', 'Action completed'],
        ['closed', 'Closed'],
        ['filed', 'Filed'],
    ];
    const activeStatusOptions = [
        ['under_review', 'Under review'],
        ['forwarded', 'Forwarded'],
        ['action_required', 'Action required'],
        ['awaiting_response', 'Awaiting response'],
        ['responded', 'Responded'],
    ];
    const statusOptions =
        form.data.action_type === 'filed' ? [['filed', 'Filed']] : form.data.action_type === 'closed' ? [['closed', 'Closed']] : activeStatusOptions;

    const changeDirection = (direction: string) => {
        const selectedOfficerId = target?.assignment_target_type === 'individual' ? String(target.id) : '';

        form.setData((current) => ({
            ...current,
            direction,
            action_type: direction === 'ps_to_department' ? 'forwarded_for_action' : 'returned_to_ps',
            status_after: direction === 'ps_to_department' ? 'action_required' : 'responded',
            responsible_user_id: direction === 'ps_to_department' ? selectedOfficerId : '',
            due_date: '',
            confirm_duplicate: false,
        }));
    };
    const changeAction = (actionType: string) => {
        form.setData((current) => ({
            ...current,
            action_type: actionType,
            status_after:
                actionType === 'filed'
                    ? 'filed'
                    : actionType === 'closed'
                      ? 'closed'
                      : ['filed', 'closed'].includes(current.status_after)
                        ? current.direction === 'ps_to_department'
                            ? 'action_required'
                            : 'responded'
                        : current.status_after,
        }));
    };
    const changeTarget = (recipient: RecipientSuggestion | null) => {
        setTarget(recipient);

        form.setData((current) => ({
            ...current,
            organizational_unit_id: recipient?.organizational_unit_id ? String(recipient.organizational_unit_id) : '',
            responsible_user_id:
                recipient?.assignment_target_type === 'individual' && current.direction === 'ps_to_department' ? String(recipient.id) : '',
            due_date: recipient?.assignment_target_type === 'individual' ? current.due_date : '',
        }));
        form.clearErrors('organizational_unit_id', 'responsible_user_id');
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('mail.department-interactions.store', mailId), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal
            title="Add Correspondence"
            className="add-correspondence-modal"
            size="wide"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="department-interaction-form"
                        className="btn btn-primary"
                        disabled={form.processing || !form.data.organizational_unit_id || !form.data.annotation.trim() || !form.data.occurred_at}
                    >
                        {form.processing ? <LoaderCircle className="spin" aria-hidden="true" /> : <Save aria-hidden="true" />}
                        {form.processing ? 'Recording…' : 'Record movement'}
                    </button>
                </>
            }
        >
            <p className="mail-modal-hint" role="note">
                Select the department or responsible officer, then record the note or annotation. Your account remains the recorded user.
            </p>
            <form id="department-interaction-form" onSubmit={submit} encType="multipart/form-data">
                <FormErrorSummary errors={form.errors} />
                <div className="mail-form-grid">
                    <RecipientPicker
                        selected={target}
                        onSelect={changeTarget}
                        searchRoute={route('mail.department-interactions.recipient-search', mailId)}
                        label="Department or officer title"
                        placeholder="Search C/LEIT, C/HRM, an officer name, title, office or department"
                        error={form.errors.organizational_unit_id || form.errors.responsible_user_id}
                    />
                    <Field label="Note or annotation" required wide>
                        <textarea
                            className="textarea"
                            rows={5}
                            maxLength={10000}
                            value={form.data.annotation}
                            onChange={(event) => form.setData('annotation', event.target.value)}
                            placeholder="Enter the instruction, response, or action taken…"
                            required
                        />
                    </Field>
                    <details className="department-interaction-advanced mail-field-wide">
                        <summary>Additional movement details</summary>
                        <div className="mail-form-grid">
                            <Field label="Movement direction" required>
                                <select className="select" value={form.data.direction} onChange={(event) => changeDirection(event.target.value)}>
                                    <option value="ps_to_department">PS Office → department</option>
                                    <option value="department_to_ps">Department → PS Office</option>
                                </select>
                            </Field>
                            <Field label="Action type" required>
                                <select className="select" value={form.data.action_type} onChange={(event) => changeAction(event.target.value)}>
                                    {(form.data.direction === 'ps_to_department' ? outboundActions : returnActions).map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Status after movement" required>
                                <select
                                    className="select"
                                    value={form.data.status_after}
                                    onChange={(event) => form.setData('status_after', event.target.value)}
                                >
                                    {statusOptions.map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                label="When the movement occurred"
                                required
                                hint="Historical dates are allowed; the recording time remains separate."
                            >
                                <input
                                    className="input"
                                    type="datetime-local"
                                    max={nowForDateTimeInput()}
                                    value={form.data.occurred_at}
                                    onChange={(event) => form.setData('occurred_at', event.target.value)}
                                />
                            </Field>
                            {form.data.responsible_user_id && (
                                <Field label="Assignment due date">
                                    <input
                                        className="input"
                                        type="date"
                                        min={todayForDateInput()}
                                        value={form.data.due_date}
                                        onChange={(event) => form.setData('due_date', event.target.value)}
                                    />
                                </Field>
                            )}
                            <Field label="Attachments" wide>
                                <label className="assignment-file-picker">
                                    <Paperclip aria-hidden="true" />
                                    <span>
                                        {form.data.attachments.length > 0
                                            ? `${form.data.attachments.length} file(s) selected`
                                            : 'Choose optional files'}
                                    </span>
                                    <input
                                        type="file"
                                        multiple
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.mp4,.webm"
                                        onChange={(event) => form.setData('attachments', Array.from(event.target.files ?? []))}
                                    />
                                </label>
                            </Field>
                        </div>
                    </details>
                    {form.errors.duplicate_confirmation && (
                        <label className="field mail-field-wide">
                            <span>Possible duplicate detected</span>
                            <span className="mail-modal-hint">{form.errors.duplicate_confirmation}</span>
                            <span className="check-row">
                                <input
                                    type="checkbox"
                                    checked={form.data.confirm_duplicate}
                                    onChange={(event) => form.setData('confirm_duplicate', event.target.checked)}
                                />
                                I reviewed the existing entry and confirm this is a legitimate repeat movement.
                            </span>
                        </label>
                    )}
                </div>
            </form>
        </Modal>
    );
}
