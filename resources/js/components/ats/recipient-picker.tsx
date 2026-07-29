import { SearchLoader } from '@/components/ats/search-loader';
import { Building2, Check, Hash, Search, UserRound, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';

export interface RecipientSuggestion {
    id: number;
    recipient_type: 'officer' | 'position' | 'department' | 'directorate' | 'unit' | 'office';
    name: string;
    title: string | null;
    department_id: number | null;
    department: string | null;
    context: string | null;
    office: string | null;
    shorthand_code: string | null;
    staff_id: string | null;
    status: string;
    initials: string;
}

interface RecipientPickerProps {
    mailId: number;
    selected: RecipientSuggestion | null;
    onSelect: (recipient: RecipientSuggestion | null) => void;
    error?: string;
}

const typeLabels: Record<RecipientSuggestion['recipient_type'], string> = {
    officer: 'Officer',
    position: 'Position',
    department: 'Department',
    directorate: 'Directorate',
    unit: 'Unit',
    office: 'Office',
};

export default function RecipientPicker({ mailId, selected, onSelect, error }: RecipientPickerProps) {
    const inputId = useId();
    const listId = `${inputId}-results`;
    const inputRef = useRef<HTMLInputElement>(null);
    const debounce = useRef<ReturnType<typeof setTimeout>>(null);
    const request = useRef<AbortController | null>(null);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<RecipientSuggestion[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [searched, setSearched] = useState(false);
    const [failed, setFailed] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);

    useEffect(() => {
        return () => {
            if (debounce.current !== null) clearTimeout(debounce.current);
            request.current?.abort();
        };
    }, []);

    const search = (value: string) => {
        setQuery(value);
        setOpen(true);
        setActiveIndex(-1);
        setFailed(false);
        if (selected !== null) onSelect(null);
        if (debounce.current !== null) clearTimeout(debounce.current);
        request.current?.abort();

        const term = value.trim();
        if (term.length < 2) {
            setResults([]);
            setSearched(false);
            setLoading(false);
            return;
        }

        setLoading(true);
        debounce.current = setTimeout(async () => {
            const controller = new AbortController();
            request.current = controller;
            try {
                const response = await fetch(`${route('mail.recipient-search', mailId)}?q=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Recipient search failed.');
                const payload = (await response.json()) as { recipients: RecipientSuggestion[] };
                setResults(payload.recipients);
                setSearched(true);
            } catch (searchError) {
                if (searchError instanceof DOMException && searchError.name === 'AbortError') return;
                setResults([]);
                setSearched(true);
                setFailed(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 250);
    };

    const pick = (recipient: RecipientSuggestion) => {
        onSelect(recipient);
        setQuery(recipient.name);
        setOpen(false);
        setActiveIndex(-1);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setOpen(true);
            setActiveIndex((current) => Math.min(current + 1, results.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((current) => Math.max(current - 1, 0));
        } else if (event.key === 'Enter' && open && activeIndex >= 0) {
            event.preventDefault();
            pick(results[activeIndex]);
        } else if (event.key === 'Escape') {
            setOpen(false);
            setActiveIndex(-1);
        }
    };

    if (selected !== null) {
        return (
            <div className="field recipient-picker-field mail-field-wide">
                <label>Responsible recipient *</label>
                <div className="recipient-selected">
                    <span className="recipient-avatar" aria-hidden="true">
                        {selected.initials}
                    </span>
                    <div className="recipient-selected-copy">
                        <span className="recipient-result-topline">
                            <strong>{selected.name}</strong>
                            <span className="recipient-type-badge">
                                <Check /> Selected
                            </span>
                        </span>
                        <span>{selected.title || 'Ministry staff member'}</span>
                        <small>{[selected.department, selected.context].filter(Boolean).join(' · ') || 'Central office'}</small>
                        {selected.shorthand_code && <em>Code: {selected.shorthand_code}</em>}
                    </div>
                    <button
                        type="button"
                        className="recipient-change"
                        onClick={() => {
                            onSelect(null);
                            setQuery('');
                            setTimeout(() => inputRef.current?.focus(), 0);
                        }}
                    >
                        <X aria-hidden="true" /> Change
                    </button>
                </div>
                {error && <div className="field-error">{error}</div>}
            </div>
        );
    }

    return (
        <div className="field recipient-picker-field mail-field-wide">
            <label htmlFor={inputId}>Responsible recipient *</label>
            <div className="recipient-combobox">
                <Search className="recipient-search-icon" aria-hidden="true" />
                <input
                    ref={inputRef}
                    id={inputId}
                    className="input recipient-search-input"
                    value={query}
                    onChange={(event) => search(event.target.value)}
                    onFocus={() => setOpen(true)}
                    onBlur={() => setTimeout(() => setOpen(false), 160)}
                    onKeyDown={onKeyDown}
                    placeholder="Search name, title, department or code (for example PS/ES)"
                    autoComplete="off"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-activedescendant={activeIndex >= 0 ? `${listId}-${results[activeIndex]?.id}` : undefined}
                />
                {loading && (
                    <span className="recipient-searching" role="status">
                        <SearchLoader compact label="Searching directory…" />
                    </span>
                )}

                {open && query.trim().length >= 2 && (
                    <div id={listId} className="recipient-results" role="listbox">
                        {results.map((recipient, index) => (
                            <button
                                id={`${listId}-${recipient.id}`}
                                key={recipient.id}
                                type="button"
                                role="option"
                                aria-selected={index === activeIndex}
                                className={`recipient-result ${index === activeIndex ? 'is-active' : ''}`}
                                onMouseDown={(event) => event.preventDefault()}
                                onMouseEnter={() => setActiveIndex(index)}
                                onClick={() => pick(recipient)}
                            >
                                <span className="recipient-avatar" aria-hidden="true">
                                    {recipient.initials}
                                </span>
                                <span className="recipient-result-copy">
                                    <span className="recipient-result-topline">
                                        <strong>{recipient.name}</strong>
                                        <span className="recipient-type-badge">{typeLabels[recipient.recipient_type]}</span>
                                    </span>
                                    <span>{recipient.title || 'Ministry staff member'}</span>
                                    <small>
                                        <Building2 /> {[recipient.department, recipient.context].filter(Boolean).join(' · ') || 'Central office'}
                                    </small>
                                    <span className="recipient-result-meta">
                                        {recipient.shorthand_code && (
                                            <em>
                                                <Hash /> {recipient.shorthand_code}
                                            </em>
                                        )}
                                        {recipient.staff_id && <em>Staff ID {recipient.staff_id}</em>}
                                        <em className="recipient-status">{recipient.status}</em>
                                    </span>
                                </span>
                            </button>
                        ))}
                        {searched && !loading && results.length === 0 && (
                            <div className="recipient-empty">
                                <UserRound aria-hidden="true" />
                                <strong>
                                    {failed ? 'The directory search could not be completed.' : 'No matching officer, office or department was found.'}
                                </strong>
                                <span>{failed ? 'Please try again.' : 'Try a name, title, department, unit or official shorthand code.'}</span>
                            </div>
                        )}
                    </div>
                )}
            </div>
            <small className="recipient-help">Search begins after two characters. Use ↑ and ↓ to move, Enter to select, and Esc to close.</small>
            {error && <div className="field-error">{error}</div>}
        </div>
    );
}
