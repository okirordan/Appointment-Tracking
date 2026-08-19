import { SearchLoader } from '@/components/ats/search-loader';
import { Check, Hash, Plus, Search, X } from '@/components/icons';
import { pushToast } from '@/lib/toast';
import { useEffect, useId, useRef, useState, type FocusEvent } from 'react';

export interface AnnotationTitleOption {
    id: number;
    shorthand: string;
    full_title: string;
    label: string;
}

interface Props {
    label: string;
    selected: AnnotationTitleOption | null;
    onSelect: (title: AnnotationTitleOption | null) => void;
    placeholder?: string;
    hint?: string;
    error?: string;
}

export default function AnnotationTitlePicker({ label, selected, onSelect, placeholder = 'Search shorthand or full title…', hint, error }: Props) {
    const inputId = useId();
    const debounce = useRef<ReturnType<typeof setTimeout>>(null);
    const request = useRef<AbortController | null>(null);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<AnnotationTitleOption[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [creating, setCreating] = useState(false);
    const [fullTitle, setFullTitle] = useState('');
    const [saving, setSaving] = useState(false);
    const [createError, setCreateError] = useState('');

    useEffect(
        () => () => {
            if (debounce.current !== null) clearTimeout(debounce.current);
            request.current?.abort();
        },
        [],
    );

    const search = (value: string) => {
        setQuery(value);
        setOpen(true);
        setFailed(false);
        setCreating(false);
        setCreateError('');
        if (debounce.current !== null) clearTimeout(debounce.current);
        request.current?.abort();
        if (value.trim().length < 1) {
            setResults([]);
            setLoading(false);
            return;
        }

        setLoading(true);
        debounce.current = setTimeout(async () => {
            const controller = new AbortController();
            request.current = controller;
            try {
                const response = await fetch(`${route('annotation-titles.index')}?q=${encodeURIComponent(value.trim())}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Search failed');
                const payload = (await response.json()) as { titles: AnnotationTitleOption[] };
                setResults(payload.titles);
            } catch (searchError) {
                if (searchError instanceof DOMException && searchError.name === 'AbortError') return;
                setResults([]);
                setFailed(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 220);
    };

    const create = async () => {
        if (!query.trim() || !fullTitle.trim() || saving) return;
        setSaving(true);
        setCreateError('');
        try {
            const response = await fetch(route('annotation-titles.store'), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ shorthand: query.trim(), full_title: fullTitle.trim() }),
            });
            const contentType = response.headers.get('content-type') ?? '';
            const payload = contentType.includes('application/json')
                ? ((await response.json()) as { title?: AnnotationTitleOption; message?: string; errors?: Record<string, string[]> })
                : {};
            if (!response.ok || !payload.title || !Number.isInteger(payload.title.id) || payload.title.id < 1) {
                throw new Error(Object.values(payload.errors ?? {})[0]?.[0] ?? payload.message ?? 'Unable to create this annotation title.');
            }
            onSelect(payload.title);
            setQuery('');
            setFullTitle('');
            setCreating(false);
            setOpen(false);
            pushToast('success', payload.message ?? 'Annotation title created and selected.');
        } catch (createError) {
            const message = createError instanceof Error ? createError.message : 'Unable to create this annotation title.';
            setCreateError(message);
            pushToast('error', message);
        } finally {
            setSaving(false);
        }
    };

    const closeWhenFocusLeaves = (event: FocusEvent<HTMLDivElement>) => {
        const nextTarget = event.relatedTarget;

        // The create form is rendered inside the combobox. Moving focus from
        // the search box to its full-title input or Save button must not close
        // the dropdown before the user can finish creating the source.
        if (nextTarget instanceof Node && event.currentTarget.contains(nextTarget)) return;

        setOpen(false);
    };

    if (selected !== null) {
        return (
            <div className="field annotation-title-field">
                <label>{label}</label>
                <div className="annotation-title-selected">
                    <span className="annotation-title-code">
                        <Hash aria-hidden="true" />
                        {selected.shorthand}
                    </span>
                    <span>{selected.full_title}</span>
                    <button type="button" onClick={() => onSelect(null)} aria-label={`Clear ${label}`}>
                        <X aria-hidden="true" />
                    </button>
                </div>
                {hint && <span className="field-help">{hint}</span>}
                {error && <div className="field-error">{error}</div>}
            </div>
        );
    }

    return (
        <div className="field annotation-title-field">
            <label htmlFor={inputId}>{label}</label>
            <div className="annotation-title-combobox" onBlur={closeWhenFocusLeaves}>
                <Search aria-hidden="true" />
                <input
                    id={inputId}
                    value={query}
                    onChange={(event) => search(event.target.value)}
                    onFocus={() => query.trim() && setOpen(true)}
                    placeholder={placeholder}
                    autoComplete="off"
                    role="combobox"
                    aria-expanded={open}
                />
                {loading && <SearchLoader compact label="Searching titles…" />}
                {open && query.trim() && (
                    <div className="annotation-title-results" role="listbox">
                        {results.map((title) => (
                            <button key={title.id} type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => onSelect(title)}>
                                <span>
                                    <Hash aria-hidden="true" />
                                    <strong>{title.shorthand}</strong>
                                </span>
                                <small>{title.full_title}</small>
                                <Check aria-hidden="true" />
                            </button>
                        ))}
                        {!loading && !failed && (
                            <button
                                type="button"
                                className="annotation-title-add"
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => {
                                    setCreateError('');
                                    setCreating(true);
                                }}
                            >
                                <Plus aria-hidden="true" /> Add “{query.trim()}”
                            </button>
                        )}
                        {failed && <div className="annotation-title-error">The shared title directory could not be loaded. Please try again.</div>}
                        {creating && (
                            <div className="annotation-title-create" onMouseDown={(event) => event.preventDefault()}>
                                <strong>New shared annotation title</strong>
                                <span>Shorthand: {query.trim().toUpperCase()}</span>
                                <input
                                    value={fullTitle}
                                    onChange={(event) => setFullTitle(event.target.value)}
                                    placeholder="e.g. Commissioner Library, E-learning and Information Technology"
                                    autoFocus
                                />
                                <button type="button" disabled={saving || !fullTitle.trim()} onClick={create}>
                                    {saving ? 'Saving…' : 'Save and select'}
                                </button>
                                {createError && <div className="annotation-title-error">{createError}</div>}
                            </div>
                        )}
                    </div>
                )}
            </div>
            {hint && <span className="field-help">{hint}</span>}
            {error && <div className="field-error">{error}</div>}
        </div>
    );
}
