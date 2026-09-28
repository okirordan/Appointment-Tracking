import { SearchLoader } from '@/components/ats/search-loader';
import { Check, Plus, Search, UserRound, X } from '@/components/icons';
import { csrfHeaders } from '@/lib/csrf';
import { pushToast } from '@/lib/toast';
import { useEffect, useId, useRef, useState, type FocusEvent } from 'react';

export interface MailNamedOfficerOption {
    id: number;
    full_name: string;
}

export default function MailNamedOfficerPicker({
    selected,
    onSelect,
    error,
}: {
    selected: MailNamedOfficerOption | null;
    onSelect: (officer: MailNamedOfficerOption | null) => void;
    error?: string;
}) {
    const inputId = useId();
    const inputRef = useRef<HTMLInputElement>(null);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<MailNamedOfficerOption[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const [active, setActive] = useState(-1);

    useEffect(() => {
        if (!open || selected || query.trim().length < 2) return;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setLoading(true);
            try {
                const response = await fetch(`${route('mail.named-officers.index')}?q=${encodeURIComponent(query.trim())}`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Search failed');
                const payload = (await response.json()) as { officers: MailNamedOfficerOption[] };
                if (!controller.signal.aborted) setResults(payload.officers);
            } catch {
                if (!controller.signal.aborted) setMessage('Saved names could not be loaded. You can still save this name.');
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 220);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, selected]);

    const select = (officer: MailNamedOfficerOption) => {
        onSelect(officer);
        setQuery('');
        setOpen(false);
        setResults([]);
        setMessage('');
    };

    const save = async () => {
        const name = query.trim();
        if (name.length < 2 || saving) return;
        setSaving(true);
        setMessage('');
        try {
            const response = await fetch(route('mail.named-officers.store'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                body: JSON.stringify({ full_name: name }),
            });
            const payload = (await response.json()) as {
                officer?: MailNamedOfficerOption;
                message?: string;
                errors?: Record<string, string[]>;
            };
            if (!response.ok || !payload.officer || !Number.isInteger(payload.officer.id) || payload.officer.id < 1) {
                throw new Error(payload.errors?.full_name?.[0] ?? payload.message ?? 'Unable to save this officer name.');
            }
            select(payload.officer);
            pushToast('success', payload.message ?? 'Officer name saved and selected.');
        } catch (error) {
            const failure = error instanceof Error ? error.message : 'Unable to save this officer name.';
            setMessage(failure);
            pushToast('error', failure);
        } finally {
            setSaving(false);
        }
    };

    const closeWhenFocusLeaves = (event: FocusEvent<HTMLDivElement>) => {
        const next = event.relatedTarget;
        if (next instanceof Node && event.currentTarget.contains(next)) return;
        setOpen(false);
    };

    if (selected) {
        return (
            <div className="field annotation-title-field mail-field-wide">
                <label>Officer name</label>
                <div className="annotation-title-selected">
                    <span className="annotation-title-code">
                        <UserRound aria-hidden="true" /> {selected.full_name}
                    </span>
                    <button
                        type="button"
                        aria-label="Change officer name"
                        onClick={() => {
                            onSelect(null);
                            setTimeout(() => inputRef.current?.focus(), 0);
                        }}
                    >
                        <X aria-hidden="true" />
                    </button>
                </div>
                <span className="field-help">This records the name without creating an account or routing an assignment.</span>
                {error && <div className="field-error">{error}</div>}
            </div>
        );
    }

    return (
        <div className="field annotation-title-field mail-field-wide">
            <label htmlFor={inputId}>Officer name</label>
            <div className="annotation-title-combobox" onBlur={closeWhenFocusLeaves}>
                <Search aria-hidden="true" />
                <input
                    id={inputId}
                    ref={inputRef}
                    role="combobox"
                    aria-expanded={open && query.trim().length >= 2}
                    aria-controls={`${inputId}-results`}
                    aria-activedescendant={active >= 0 ? `${inputId}-option-${active}` : undefined}
                    autoComplete="off"
                    placeholder="Search or enter an officer name"
                    value={query}
                    disabled={saving}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setResults([]);
                        setMessage('');
                        setActive(-1);
                        setOpen(true);
                    }}
                    onFocus={() => query.trim().length >= 2 && setOpen(true)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            if (active >= 0 && results[active]) select(results[active]);
                        } else if (event.key === 'ArrowDown' && results.length) {
                            event.preventDefault();
                            setActive((current) => (current + 1) % results.length);
                        } else if (event.key === 'ArrowUp' && results.length) {
                            event.preventDefault();
                            setActive((current) => (current + results.length - 1) % results.length);
                        } else if (event.key === 'Escape') {
                            setOpen(false);
                        }
                    }}
                />
                {loading && <SearchLoader compact label="Searching saved names…" />}
                {open && query.trim().length >= 2 && (
                    <div className="annotation-title-results" id={`${inputId}-results`} role="listbox" aria-label="Saved officer names">
                        {results.map((officer, index) => (
                            <button
                                key={officer.id}
                                id={`${inputId}-option-${index}`}
                                type="button"
                                role="option"
                                disabled={saving}
                                aria-selected={active === index}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => select(officer)}
                            >
                                <span>
                                    <UserRound aria-hidden="true" /> <strong>{officer.full_name}</strong>
                                </span>
                                <Check aria-hidden="true" />
                            </button>
                        ))}
                        {!loading && !results.some((officer) => officer.full_name.toLocaleLowerCase() === query.trim().toLocaleLowerCase()) && (
                            <button type="button" className="annotation-title-add" disabled={saving} onClick={() => void save()}>
                                <Plus aria-hidden="true" /> Save {query.trim()} and select
                            </button>
                        )}
                        {message && (
                            <div className="annotation-title-error" role="alert">
                                {message}
                            </div>
                        )}
                    </div>
                )}
            </div>
            <span className="field-help">For an officer who has no staff account yet. This saves a name for future mail entries.</span>
            {error && <div className="field-error">{error}</div>}
        </div>
    );
}
