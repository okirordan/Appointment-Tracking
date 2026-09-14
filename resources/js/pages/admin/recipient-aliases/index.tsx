import AppShell from '@/components/ats/app-shell';
import FormErrorSummary from '@/components/ats/form-error-summary';
import { Clock3, Edit3, Hash, Power, RotateCcw, Save, Tags } from '@/components/icons';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState, type FormEvent } from 'react';

interface AliasHistory {
    id: number;
    action: string;
    actor: string;
    when: string;
    changes: Record<string, unknown> | null;
}

interface AliasRow {
    id: number;
    alias: string;
    target_type: string;
    target_id: number;
    target_label: string;
    active: boolean;
    updated_by: string;
    updated_at: string;
    history: AliasHistory[];
}

interface Option {
    id: number;
    label: string;
    meta: string | null;
}

interface Props {
    aliases: AliasRow[];
    titles: SharedTitle[];
    targetTypes: { value: string; label: string }[];
    targetOptions: Record<string, Option[]>;
}

interface SharedTitle {
    id: number;
    shorthand: string;
    full_title: string;
    active: boolean;
    created_by: string;
    routing_links: number;
}

function SharedTitleDirectory({ titles, onLinkRecipient }: { titles: SharedTitle[]; onLinkRecipient: (title: SharedTitle) => void }) {
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState<SharedTitle | null>(null);
    const [toggling, setToggling] = useState<number | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const form = useForm({ shorthand: '', full_title: '' });
    const toggleForm = useForm({});
    const normalizedQuery = query.toLocaleLowerCase().replace(/[^a-z0-9]/g, '');
    const matching = titles.filter((title) =>
        [title.shorthand, title.full_title].some((value) =>
            value
                .toLocaleLowerCase()
                .replace(/[^a-z0-9]/g, '')
                .includes(normalizedQuery),
        ),
    );
    const reset = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing === null) {
            form.post(route('admin.annotation-titles.store'), { preserveScroll: true, onSuccess: reset });
        } else {
            form.put(route('admin.annotation-titles.update', editing.id), { preserveScroll: true, onSuccess: reset });
        }
    };
    const startEdit = (title: SharedTitle) => {
        setEditing(title);
        form.setData({ shorthand: title.shorthand, full_title: title.full_title });
        form.clearErrors();
        inputRef.current?.scrollIntoView({ block: 'center' });
        inputRef.current?.focus({ preventScroll: true });
    };

    return (
        <section className="mb-6 border border-[var(--border)] bg-[var(--card)] p-4" aria-labelledby="shared-directory-heading">
            <h2 id="shared-directory-heading" className="mb-2 text-lg font-semibold text-[var(--title)]">
                Shared departments and officer titles
            </h2>
            <p className="mb-4 text-sm text-[var(--label)]">
                One directory for recording mail, forwarding and annotations, including entries added by secretaries. A title on its own records the
                designation; it does not assign mail to an officer. Link a recipient below to make the shorthand available in recipient searches.
            </p>
            <form
                onSubmit={submit}
                className="mb-5 border-b border-[var(--border)] pb-4"
                aria-label={editing ? 'Edit shared title' : 'Add shared title'}
            >
                <h3 className="mb-3 text-sm font-semibold text-[var(--title)]">
                    {editing ? `Edit ${editing.shorthand}` : 'Add department or officer title'}
                </h3>
                <FormErrorSummary errors={form.errors} />
                <div className="grid items-start gap-3 md:grid-cols-[1fr_2fr]">
                    <div className="field">
                        <label htmlFor="shared-title-shorthand">Abbreviation *</label>
                        <input
                            ref={inputRef}
                            id="shared-title-shorthand"
                            className="input"
                            value={form.data.shorthand}
                            onChange={(event) => form.setData('shorthand', event.target.value)}
                            placeholder="e.g. C/HRM"
                            maxLength={100}
                            required
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="shared-title-full">Full department name or officer designation *</label>
                        <input
                            id="shared-title-full"
                            className="input"
                            value={form.data.full_title}
                            onChange={(event) => form.setData('full_title', event.target.value)}
                            placeholder="e.g. Commissioner Human Resource Management"
                            maxLength={255}
                            required
                        />
                    </div>
                </div>
                <div className="mt-3 flex flex-wrap justify-end gap-2">
                    {editing && (
                        <button type="button" className="btn btn-ghost" disabled={form.processing} onClick={reset}>
                            Cancel edit
                        </button>
                    )}
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        <Save aria-hidden="true" /> {form.processing ? 'Saving…' : editing ? 'Save title' : 'Add shared title'}
                    </button>
                </div>
            </form>
            <div className="field">
                <label htmlFor="shared-title-search">Search the shared directory</label>
                <input
                    id="shared-title-search"
                    className="input"
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Search abbreviation, department or officer title"
                />
            </div>
            <p className="my-3 text-xs text-[var(--label)]" role="status">
                Showing {matching.length} of {titles.length} shared titles.
            </p>
            <FormErrorSummary errors={toggleForm.errors} />
            <div className="max-h-96 overflow-y-auto">
                {matching.length === 0 && (
                    <p className="py-4 text-sm text-[var(--label)]">
                        No matching title. Use the form above to add the missing department or designation.
                    </p>
                )}
                {matching.map((title) => (
                    <article key={title.id} className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] py-3">
                        <div className="min-w-0 flex-1 basis-64">
                            <div className="mb-1 flex flex-wrap items-center gap-2">
                                <strong className="text-sm break-words text-[var(--title)]">{title.shorthand}</strong>
                                <span className="border border-[var(--border)] px-2 py-0.5 text-xs text-[var(--label)]">
                                    {title.active ? 'Active' : 'Inactive'}
                                </span>
                            </div>
                            <p className="text-sm break-words text-[var(--body)]">{title.full_title}</p>
                            <p className="mt-1 text-xs text-[var(--label)]">
                                Added by {title.created_by} ·{' '}
                                {title.routing_links === 0
                                    ? 'No recipient link'
                                    : `${title.routing_links} recipient ${title.routing_links === 1 ? 'link' : 'links'}`}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-1">
                            <button
                                type="button"
                                className="btn btn-ghost"
                                disabled={form.processing}
                                onClick={() => startEdit(title)}
                                aria-label={`Edit ${title.shorthand}`}
                            >
                                <Edit3 aria-hidden="true" /> Edit
                            </button>
                            <button
                                type="button"
                                className="btn btn-ghost"
                                onClick={() => onLinkRecipient(title)}
                                aria-label={`Link recipient to ${title.shorthand}`}
                            >
                                Link recipient
                            </button>
                            <button
                                type="button"
                                className="btn btn-ghost"
                                disabled={toggleForm.processing}
                                aria-label={`${title.active ? 'Deactivate' : 'Activate'} ${title.shorthand}`}
                                onClick={() => {
                                    setToggling(title.id);
                                    toggleForm.post(route('admin.annotation-titles.toggle', title.id), {
                                        preserveScroll: true,
                                        onFinish: () => setToggling(null),
                                    });
                                }}
                            >
                                <Power aria-hidden="true" /> {toggling === title.id ? 'Saving…' : title.active ? 'Deactivate' : 'Activate'}
                            </button>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}

export default function RecipientAliases({ aliases, titles, targetTypes, targetOptions }: Props) {
    const aliasInput = useRef<HTMLInputElement>(null);
    const [editing, setEditing] = useState<AliasRow | null>(null);
    const [historyId, setHistoryId] = useState<number | null>(null);
    const form = useForm({ alias: '', target_type: 'position', target_id: '' as string | number });
    const options = useMemo(() => targetOptions[form.data.target_type] ?? [], [form.data.target_type, targetOptions]);

    const reset = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing === null) {
            form.post(route('admin.recipient-aliases.store'), { preserveScroll: true, onSuccess: reset });
        } else {
            form.put(route('admin.recipient-aliases.update', editing.id), { preserveScroll: true, onSuccess: reset });
        }
    };

    const startEdit = (alias: AliasRow) => {
        setEditing(alias);
        form.setData({ alias: alias.alias, target_type: alias.target_type, target_id: alias.target_id });
        form.clearErrors();
        aliasInput.current?.scrollIntoView({ block: 'center' });
        aliasInput.current?.focus({ preventScroll: true });
    };

    const linkRecipient = (title: SharedTitle) => {
        setEditing(null);
        form.setData({ alias: title.shorthand, target_type: 'position', target_id: '' });
        form.clearErrors();
        aliasInput.current?.scrollIntoView({ block: 'center' });
        aliasInput.current?.focus({ preventScroll: true });
    };

    return (
        <AppShell title="Recipient Shorthand">
            <div className="[--pri-soft:#fff3dc] [--pri:#a61b1b] [--title:#25221e] dark:[--pri-soft:#362e1d] dark:[--pri:#ffb4ae] dark:[--title:#f3eee3] [&_.alias-icon]:rounded-none! [&_.alias-row]:rounded-none! [&_.alias-row:hover]:translate-y-0! [&_.alias-row:hover]:shadow-none! [&_.card]:rounded-none! [&_.card]:shadow-none!">
                <div className="page-hd">
                    <div>
                        <span className="result-eyebrow">Directory configuration</span>
                        <h1>Departments, titles and shorthand</h1>
                    </div>
                </div>

                <SharedTitleDirectory titles={titles} onLinkRecipient={linkRecipient} />
                <div className="alias-admin-layout">
                    <form className="card alias-form-card" onSubmit={submit}>
                        <div className="alias-card-heading">
                            <span className="alias-icon">
                                <Tags aria-hidden="true" />
                            </span>
                            <div>
                                <h2>{editing ? `Edit ${editing.alias}` : 'Link shorthand to a recipient'}</h2>
                                <p>
                                    Choose the existing officer, position or department that this shorthand should find. Codes ignore punctuation and
                                    spacing.
                                </p>
                            </div>
                        </div>
                        <FormErrorSummary errors={form.errors} />
                        <div className="field">
                            <label htmlFor="alias-code">Shorthand or alias *</label>
                            <input
                                id="alias-code"
                                ref={aliasInput}
                                className="input"
                                value={form.data.alias}
                                onChange={(event) => form.setData('alias', event.target.value)}
                                placeholder="For example C/HRM"
                                required
                            />
                        </div>
                        <div className="field">
                            <label htmlFor="alias-type">Links to *</label>
                            <select
                                id="alias-type"
                                className="select"
                                value={form.data.target_type}
                                onChange={(event) => {
                                    form.setData('target_type', event.target.value);
                                    form.setData('target_id', '');
                                }}
                            >
                                {targetTypes.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="field">
                            <label htmlFor="alias-target">Recipient, position or organisation *</label>
                            <select
                                id="alias-target"
                                className="select"
                                value={form.data.target_id}
                                onChange={(event) => form.setData('target_id', event.target.value)}
                                required
                            >
                                <option value="">Select a target</option>
                                {options.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.label}
                                        {option.meta ? ` · ${option.meta}` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="alias-form-actions">
                            {editing && (
                                <button type="button" className="btn btn-ghost" onClick={reset}>
                                    <RotateCcw aria-hidden="true" /> Cancel
                                </button>
                            )}
                            <button type="submit" className="btn btn-primary" disabled={form.processing}>
                                <Save aria-hidden="true" /> {editing ? 'Save changes' : 'Save recipient link'}
                            </button>
                        </div>
                    </form>

                    <section className="card alias-list-card">
                        <div className="alias-card-heading">
                            <span className="alias-icon">
                                <Hash aria-hidden="true" />
                            </span>
                            <div>
                                <h2>Recipient links</h2>
                                <p>
                                    {aliases.length} configured recipient {aliases.length === 1 ? 'link' : 'links'}.
                                </p>
                            </div>
                        </div>
                        <div className="alias-list">
                            {aliases.map((alias) => (
                                <article className={`alias-row ${alias.active ? '' : 'is-inactive'}`} key={alias.id}>
                                    <div className="alias-code-block">
                                        <strong>{alias.alias}</strong>
                                        <span className={`alias-status ${alias.active ? 'is-active' : ''}`}>
                                            {alias.active ? 'Active' : 'Inactive'}
                                        </span>
                                    </div>
                                    <div className="alias-target-copy">
                                        <span>{targetTypes.find((type) => type.value === alias.target_type)?.label}</span>
                                        <strong>{alias.target_label}</strong>
                                        <small>
                                            Updated by {alias.updated_by} · {alias.updated_at}
                                        </small>
                                    </div>
                                    <div className="alias-row-actions">
                                        <button type="button" className="btn btn-ghost" onClick={() => startEdit(alias)}>
                                            <Edit3 aria-hidden="true" /> Edit
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-ghost"
                                            onClick={() => setHistoryId(historyId === alias.id ? null : alias.id)}
                                        >
                                            <Clock3 aria-hidden="true" /> History
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-ghost"
                                            onClick={() =>
                                                router.post(route('admin.recipient-aliases.toggle', alias.id), {}, { preserveScroll: true })
                                            }
                                        >
                                            <Power aria-hidden="true" /> {alias.active ? 'Deactivate' : 'Activate'}
                                        </button>
                                    </div>
                                    {historyId === alias.id && (
                                        <div className="alias-history">
                                            {alias.history.length === 0 ? (
                                                <p>No changes have been recorded yet.</p>
                                            ) : (
                                                alias.history.map((entry) => (
                                                    <div key={entry.id}>
                                                        <span>{entry.when}</span>
                                                        <strong>{entry.action}</strong>
                                                        <small>{entry.actor}</small>
                                                    </div>
                                                ))
                                            )}
                                        </div>
                                    )}
                                </article>
                            ))}
                        </div>
                    </section>
                </div>
            </div>
        </AppShell>
    );
}
