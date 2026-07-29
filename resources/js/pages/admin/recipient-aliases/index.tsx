import AppShell from '@/components/ats/app-shell';
import FormErrorSummary from '@/components/ats/form-error-summary';
import { router, useForm } from '@inertiajs/react';
import { Clock3, Edit3, Hash, Power, RotateCcw, Save, Tags } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

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
    targetTypes: { value: string; label: string }[];
    targetOptions: Record<string, Option[]>;
}

export default function RecipientAliases({ aliases, targetTypes, targetOptions }: Props) {
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
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    return (
        <AppShell title="Recipient Shorthand">
            <div className="page-hd">
                <div>
                    <span className="result-eyebrow">Directory configuration</span>
                    <h1>Recipient shorthand</h1>
                    <div className="page-sub">Manage official communication codes and resolve position codes to the current office holder.</div>
                </div>
            </div>

            <div className="alias-admin-layout">
                <form className="card alias-form-card" onSubmit={submit}>
                    <div className="alias-card-heading">
                        <span className="alias-icon">
                            <Tags aria-hidden="true" />
                        </span>
                        <div>
                            <h3>{editing ? `Edit ${editing.alias}` : 'Add shorthand code'}</h3>
                            <p>Codes ignore punctuation and spacing during recipient searches.</p>
                        </div>
                    </div>
                    <FormErrorSummary errors={form.errors} />
                    <div className="field">
                        <label htmlFor="alias-code">Shorthand or alias *</label>
                        <input
                            id="alias-code"
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
                        <label htmlFor="alias-target">Recipient, position or organization *</label>
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
                            <Save aria-hidden="true" /> {editing ? 'Save changes' : 'Add shorthand'}
                        </button>
                    </div>
                </form>

                <section className="card alias-list-card">
                    <div className="alias-card-heading">
                        <span className="alias-icon">
                            <Hash aria-hidden="true" />
                        </span>
                        <div>
                            <h3>Approved codes</h3>
                            <p>
                                {aliases.length} configured shorthand {aliases.length === 1 ? 'record' : 'records'}.
                            </p>
                        </div>
                    </div>
                    <div className="alias-list">
                        {aliases.map((alias) => (
                            <article className={`alias-row ${alias.active ? '' : 'is-inactive'}`} key={alias.id}>
                                <div className="alias-code-block">
                                    <strong>{alias.alias}</strong>
                                    <span className={`alias-status ${alias.active ? 'is-active' : ''}`}>{alias.active ? 'Active' : 'Inactive'}</span>
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
                                        onClick={() => router.post(route('admin.recipient-aliases.toggle', alias.id), {}, { preserveScroll: true })}
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
        </AppShell>
    );
}
