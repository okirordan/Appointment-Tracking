import { SearchLoader } from '@/components/ats/search-loader';
import { useEffect, useId, useState } from 'react';

export interface StaffOfficer {
    id: number;
    full_name: string;
    title: string | null;
}

const staffLabel = (officer: StaffOfficer) => `${officer.title || 'Staff member'} — ${officer.full_name}`;

export default function StaffOfficerPicker({
    label,
    hint,
    value,
    onSelect,
    purpose = 'recipient',
    error,
    clearAfterSelection = false,
}: {
    label: string;
    hint: string;
    value: StaffOfficer | null;
    onSelect: (officer: StaffOfficer | null) => void;
    purpose?: 'origin' | 'recipient';
    error?: string;
    clearAfterSelection?: boolean;
}) {
    const id = useId();
    const [query, setQuery] = useState(value ? staffLabel(value) : '');
    const [open, setOpen] = useState(false);
    const [results, setResults] = useState<StaffOfficer[]>([]);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState(false);
    const [active, setActive] = useState(-1);

    useEffect(() => {
        if (!open || value || query.trim().length < 2) return;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`${route('tasks.assignee-search')}?q=${encodeURIComponent(query.trim())}&purpose=${purpose}`, {
                    signal: controller.signal,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error('Staff search failed');
                const payload = (await response.json()) as { users: StaffOfficer[] };
                if (!controller.signal.aborted) setResults(payload.users);
            } catch {
                if (!controller.signal.aborted) setFailure(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 220);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query, open, value, purpose]);

    const pick = (officer: StaffOfficer) => {
        setQuery(clearAfterSelection ? '' : staffLabel(officer));
        setOpen(false);
        setResults([]);
        setLoading(false);
        onSelect(officer);
    };
    const expanded = open && !value && query.trim().length >= 2;

    return (
        <div className="field staff-officer-picker">
            <label htmlFor={id}>{label}</label>
            <div className="posrel">
                <input
                    id={id}
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded={expanded}
                    aria-controls={`${id}-list`}
                    aria-activedescendant={expanded && active >= 0 ? `${id}-option-${active}` : undefined}
                    aria-describedby={`${id}-hint${error ? ` ${id}-error` : ''}`}
                    aria-invalid={!!error}
                    autoComplete="off"
                    placeholder="Search staff name or job title…"
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        onSelect(null);
                        setResults([]);
                        setFailure(false);
                        setActive(-1);
                        setLoading(event.target.value.trim().length >= 2);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => setOpen(false)}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape' && expanded) {
                            event.preventDefault();
                            event.stopPropagation();
                            setOpen(false);
                        }
                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                            event.preventDefault();
                            setOpen(true);
                            setActive((index) =>
                                results.length
                                    ? (index + (event.key === 'ArrowDown' ? 1 : results.length - 1) + results.length) % results.length
                                    : -1,
                            );
                        }
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            if (expanded && active >= 0 && results[active]) pick(results[active]);
                        }
                    }}
                />
                {expanded && (
                    <div className="dropdown staff-officer-results">
                        {loading && <SearchLoader compact label="Searching staff…" />}
                        {failure && <div role="alert">Staff search is unavailable. Please edit the search to try again.</div>}
                        <div id={`${id}-list`} role="listbox" aria-label={`${label} staff matches`}>
                            {results.map((officer, index) => (
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={active === index}
                                    id={`${id}-option-${index}`}
                                    key={officer.id}
                                    className="dropdown-item"
                                    onMouseDown={(event) => event.preventDefault()}
                                    onClick={() => pick(officer)}
                                >
                                    <strong>{officer.title || 'Staff member'}</strong>
                                    <span>{officer.full_name}</span>
                                </button>
                            ))}
                        </div>
                        {!loading && !failure && !results.length && <div role="status">No matching active staff. Try a name or job title.</div>}
                    </div>
                )}
            </div>
            <span id={`${id}-hint`} className="field-help">
                {hint}
            </span>
            {error && (
                <div id={`${id}-error`} className="field-error">
                    {error}
                </div>
            )}
        </div>
    );
}
