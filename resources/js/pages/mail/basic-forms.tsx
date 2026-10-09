import MailDuplicateSuggestions from '@/components/ats/mail-duplicate-suggestions';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, type ReactNode } from 'react';
import BasicOfficePicker from './basic-office-picker';
import type { MailDetail, Props } from './index';
import { useMailUrl } from './mode';

function today() {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10);
}

export function BasicErrors({ errors }: { errors: Record<string, string> }) {
    const ref = useRef<HTMLDivElement>(null);
    const count = Object.keys(errors).length;
    useEffect(() => {
        if (count) ref.current?.focus();
    }, [count]);
    if (!count) return null;
    return (
        <div className="error-summary" role="alert" tabIndex={-1} ref={ref}>
            <strong>Please review the following:</strong>
            <ul>
                {Object.entries(errors).map(([key, message]) => (
                    <li key={key}>{message}</li>
                ))}
            </ul>
        </div>
    );
}

function Field({
    id,
    label,
    required = false,
    wide = false,
    error,
    children,
}: {
    id: string;
    label: string;
    required?: boolean;
    wide?: boolean;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className={wide ? 'full' : undefined}>
            <label htmlFor={id}>
                {label}
                {required && <span aria-hidden="true"> *</span>}
            </label>
            {children}
            {error && (
                <span className="field-error" id={`${id}-error`}>
                    {error}
                </span>
            )}
        </div>
    );
}

export function BasicMailForm({ props, mail }: { props: Props; mail?: MailDetail }) {
    const url = useMailUrl();
    const direction = mail?.direction ?? (props.direction === 'outgoing' ? 'outgoing' : 'incoming');
    const incoming = direction === 'incoming';
    const features = props.mailFeatures;
    const form = useForm({
        sender_name: '',
        sender_organisation: '',
        recipient_name: '',
        subject: '',
        details: '',
        correspondence_reference: '',
        letter_date: '',
        received_date: incoming ? today() : '',
        sent_date: incoming ? '' : today(),
        receipt_method: '',
        confidentiality: 'normal',
        registry_file_number: '',
        priority: 'medium',
        ...mail?.edit_values,
        ...(mail?.basic_source_key ? { sender_name: mail.sender_display } : {}),
        ...(mail?.basic_recipient_key ? { recipient_name: mail.addressee_display } : {}),
        basic_source_key: mail?.basic_source_key ?? '',
        basic_source_kind: 'organization',
        basic_recipient_key: mail?.basic_recipient_key ?? '',
        source_type: 'external',
        destination_type: 'external',
        status: incoming ? 'registered' : 'draft',
        requires_follow_up: false,
        copied_for_information: false,
        attachments: [] as File[],
        duplicate_override: false as boolean,
        duplicate_reason: '',
        submission_token: globalThis.crypto.randomUUID(),
    });
    const back = mail
        ? url(route('mail.show', mail.id), { view: null, section: 'details' })
        : url(route(incoming ? 'mail.incoming.index' : 'mail.outgoing.index'), { view: null });
    const input = (
        name: keyof MailDetail['edit_values'],
        label: string,
        options: { required?: boolean; wide?: boolean; type?: string; textarea?: boolean; max?: number } = {},
    ) => {
        const id = `basic-${name}`;
        const common = {
            id,
            name,
            value: form.data[name],
            required: options.required,
            maxLength: options.max ?? (name === 'subject' ? 500 : name === 'details' ? 10000 : 255),
            'aria-invalid': Boolean(form.errors[name]),
            'aria-describedby': form.errors[name] ? `${id}-error` : undefined,
            onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => form.setData(name, event.target.value),
        };
        return (
            <Field id={id} label={label} required={options.required} wide={options.wide} error={form.errors[name]}>
                {options.textarea ? (
                    <textarea {...common} placeholder="Briefly describe the mail" />
                ) : (
                    <input {...common} type={options.type ?? 'text'} />
                )}
            </Field>
        );
    };
    return (
        <form
            className="card pad"
            onSubmit={(event) => {
                event.preventDefault();
                if (mail) form.put(url(route('mail.update', mail.id), { view: null, section: 'details' }), { preserveScroll: true });
                else
                    form.post(url(route(incoming ? 'mail.incoming.store' : 'mail.outgoing.store'), { view: null, page: null }), {
                        forceFormData: true,
                    });
            }}
            encType="multipart/form-data"
        >
            <BasicErrors errors={form.errors} />
            <p className="required-note">Fields marked * are required.</p>
            <h2>{mail ? 'Edit mail' : 'Sender and routing'}</h2>
            <div className="grid">
                <Field id="basic-sender_name" label="From" required error={form.errors.basic_source_key ?? form.errors.sender_name}>
                    <BasicOfficePicker
                        id="basic-sender_name"
                        value={form.data.sender_name}
                        endpoint={route('mail.directory')}
                        source={incoming}
                        placeholder={incoming ? 'Search for a source or add a new one' : 'Search a title or enter an office'}
                        onChange={(value, key) => form.setData((data) => ({ ...data, sender_name: value, basic_source_key: key }))}
                    />
                </Field>
                {incoming && form.data.basic_source_key === 'new' && (
                    <Field id="basic-source-kind" label="Source type">
                        <select
                            id="basic-source-kind"
                            value={form.data.basic_source_kind}
                            onChange={(event) => form.setData('basic_source_kind', event.target.value)}
                        >
                            <option value="organization">Organization</option>
                            <option value="individual">Individual</option>
                            <option value="office">Office</option>
                        </select>
                    </Field>
                )}
                <Field id="basic-recipient_name" label="To" required error={form.errors.basic_recipient_key ?? form.errors.recipient_name}>
                    <BasicOfficePicker
                        id="basic-recipient_name"
                        value={form.data.recipient_name}
                        endpoint={route('mail.directory')}
                        onChange={(value, key) => form.setData((data) => ({ ...data, recipient_name: value, basic_recipient_key: key }))}
                    />
                </Field>
            </div>
            <h2>Mail details</h2>
            <div className="grid">
                {input('subject', 'Subject', { required: true, wide: true })}
                {input(incoming ? 'received_date' : 'sent_date', incoming ? 'Date received' : 'Date sent', { required: true, type: 'date' })}
                {features.correspondence_reference && input('correspondence_reference', 'Reference No.')}
                {input('details', 'Details', { textarea: true, wide: true })}
            </div>
            <details className="basic-extras">
                <summary>More options{mail ? '' : ' and attachments'}</summary>
                <div className="grid">
                    {input('sender_organisation', 'Sender organisation')}
                    {input('letter_date', 'Date on letter', { type: 'date' })}
                    {features.receipt_method && (
                        <Field id="basic-receipt" label="Receipt method">
                            <select
                                id="basic-receipt"
                                value={form.data.receipt_method}
                                onChange={(event) => form.setData('receipt_method', event.target.value)}
                            >
                                <option value="">Not specified</option>
                                {['hand', 'courier', 'email', 'post', 'other'].map((method) => (
                                    <option key={method}>{method}</option>
                                ))}
                            </select>
                        </Field>
                    )}
                    {features.confidentiality && (
                        <Field id="basic-confidentiality" label="Confidentiality">
                            <select
                                id="basic-confidentiality"
                                value={form.data.confidentiality}
                                onChange={(event) => form.setData('confidentiality', event.target.value)}
                            >
                                {['normal', 'confidential', 'restricted'].map((value) => (
                                    <option key={value}>{value}</option>
                                ))}
                            </select>
                        </Field>
                    )}
                    {features.priority && (
                        <Field id="basic-priority" label="Priority">
                            <select id="basic-priority" value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)}>
                                {props.priorityOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    {features.registry_file_number && input('registry_file_number', 'Registry file number')}
                    {!mail && features.initial_status && (
                        <Field id="basic-initial-status" label="Initial status">
                            <select
                                id="basic-initial-status"
                                value={form.data.status}
                                onChange={(event) => form.setData('status', event.target.value)}
                            >
                                {(incoming ? ['registered', 'received'] : ['draft', 'dispatched']).map((status) => (
                                    <option key={status}>{status}</option>
                                ))}
                            </select>
                        </Field>
                    )}
                    {!mail && (
                        <Field id="basic-attachments" label="Attachments" wide>
                            <input
                                id="basic-attachments"
                                type="file"
                                multiple
                                onChange={(event) => form.setData('attachments', Array.from(event.target.files ?? []))}
                            />
                        </Field>
                    )}
                </div>
                <p className="form-help">Office: {props.registerOfficeName}</p>
            </details>
            {!mail && (
                <MailDuplicateSuggestions
                    input={{
                        subject: form.data.subject,
                        sender_name: form.data.sender_name,
                        recipient_name: form.data.recipient_name,
                        correspondence_reference: form.data.correspondence_reference,
                        mail_date: incoming ? form.data.received_date : form.data.sent_date,
                    }}
                />
            )}
            {!mail && (form.errors.duplicate_override || form.data.duplicate_override || form.errors.duplicate_reason) && (
                <div className="basic-extras">
                    <h2>Possible duplicate</h2>
                    <p>{form.errors.duplicate_override}</p>
                    <label>
                        <input
                            type="checkbox"
                            checked={form.data.duplicate_override}
                            onChange={(event) => form.setData('duplicate_override', event.target.checked)}
                        />{' '}
                        I confirm this is a separate mail record
                    </label>
                    {form.data.duplicate_override && (
                        <Field id="basic-duplicate-reason" label="Reason this is not a duplicate" required>
                            <textarea
                                id="basic-duplicate-reason"
                                required
                                maxLength={1000}
                                value={form.data.duplicate_reason}
                                onChange={(event) => form.setData('duplicate_reason', event.target.value)}
                            />
                        </Field>
                    )}
                </div>
            )}
            <div className="acts form-actions">
                <button className="btn p" disabled={form.processing}>
                    {form.processing ? 'Saving…' : mail ? 'Save changes' : 'Save mail'}
                </button>
                <Link className="btn g" href={back}>
                    Cancel
                </Link>
            </div>
        </form>
    );
}

export function BasicAssignmentForm({ mail }: { mail: MailDetail }) {
    const url = useMailUrl();
    const form = useForm({
        basic_action: true,
        instructions: '',
        action_required: true,
        priority: 'medium',
        due_date: '',
    });
    return (
        <form
            className="card pad"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(
                    url(route(mail.direction === 'incoming' ? 'mail.assign' : 'mail.assign-outgoing', mail.id), { section: 'actions', view: null }),
                );
            }}
        >
            <BasicErrors errors={form.errors} />
            <p className="required-note">Fields marked * are required.</p>
            <div className="grid">
                <Field id="action-details" label="Action details" required wide error={form.errors.instructions}>
                    <textarea
                        id="action-details"
                        required
                        placeholder="Describe the action to complete"
                        maxLength={5000}
                        value={form.data.instructions}
                        onChange={(event) => form.setData('instructions', event.target.value)}
                    />
                </Field>
                <Field id="action-due" label="Due date" required error={form.errors.due_date}>
                    <input
                        id="action-due"
                        type="date"
                        required
                        min={today()}
                        value={form.data.due_date}
                        onChange={(event) => form.setData('due_date', event.target.value)}
                    />
                </Field>
                <Field id="action-status" label="Status">
                    <select id="action-status" defaultValue="Assigned">
                        <option>Assigned</option>
                        <option disabled>In progress</option>
                        <option disabled>Completed</option>
                    </select>
                </Field>
            </div>
            <p className="form-help">The action point will appear on this mail record after saving.</p>
            <div className="acts form-actions">
                <button className="btn p" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save action point'}
                </button>
                <Link className="btn g" href={url(route('mail.show', mail.id), { section: 'actions' })}>
                    Cancel
                </Link>
            </div>
        </form>
    );
}

export function BasicCorrespondenceForm({ mail }: { mail: MailDetail }) {
    const url = useMailUrl();
    const form = useForm({
        entry_method: 'basic_correspondence',
        type: 'note',
        destination_key: '',
        destination_office_snapshot: '',
        destination_kind: 'office',
        recorded_date: '',
        body: '',
    });
    return (
        <form
            className="card pad"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(url(route('mail.updates.store', mail.id), { section: 'correspondences', view: null }));
            }}
        >
            <BasicErrors errors={form.errors} />
            <p className="required-note">Fields marked * are required.</p>
            <div className="grid">
                <Field id="office" label="Receiving office" required error={form.errors.destination_key ?? form.errors.destination_office_snapshot}>
                    <BasicOfficePicker
                        id="office"
                        value={form.data.destination_office_snapshot}
                        endpoint={route('mail.correspondence-offices.index', mail.id)}
                        allowNewRecipient
                        onChange={(value, key) => form.setData((data) => ({ ...data, destination_office_snapshot: value, destination_key: key }))}
                    />
                </Field>
                {form.data.destination_key === 'new-recipient' && (
                    <Field id="recipient-kind" label="Recipient type">
                        <select
                            id="recipient-kind"
                            value={form.data.destination_kind}
                            onChange={(event) => form.setData('destination_kind', event.target.value)}
                        >
                            <option value="office">Office</option>
                            <option value="organization">Organization</option>
                            <option value="individual">Individual</option>
                        </select>
                    </Field>
                )}
                <Field id="date" label="Date recorded" required error={form.errors.recorded_date}>
                    <input
                        id="date"
                        type="date"
                        required
                        max={today()}
                        value={form.data.recorded_date}
                        onChange={(event) => form.setData('recorded_date', event.target.value)}
                    />
                </Field>
                <Field id="correspondence-details" label="Correspondence details" required wide error={form.errors.body}>
                    <textarea
                        id="correspondence-details"
                        required
                        maxLength={10000}
                        placeholder="Describe what was sent or filed"
                        value={form.data.body}
                        onChange={(event) => form.setData('body', event.target.value)}
                    />
                </Field>
            </div>
            <p className="form-help">The entry will appear on this mail record after saving.</p>
            <div className="acts form-actions">
                <button className="btn p" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save correspondence'}
                </button>
                <Link className="btn g" href={url(route('mail.show', mail.id), { section: 'correspondences' })}>
                    Cancel
                </Link>
            </div>
        </form>
    );
}
