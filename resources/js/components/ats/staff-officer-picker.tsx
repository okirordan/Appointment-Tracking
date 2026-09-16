import { SearchLoader } from '@/components/ats/search-loader';
import { Fragment, useEffect, useId, useState } from 'react';

export interface StaffOfficer {
    id: number;
    full_name: string;
    title: string | null;
    title_shorthand?: string | null;
    position_id?: number | null;
    department_id?: number | null;
    organizational_unit_id?: number | null;
    department?: string | null;
    context?: string | null;
    office?: string | null;
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
    selectedOfficers = [],
    onSelectionChange,
    searchRoute,
}: {
    label: string;
    hint: string;
    value: StaffOfficer | null;
    onSelect: (officer: StaffOfficer | null) => void;
    purpose?: 'origin' | 'recipient';
    error?: string;
    clearAfterSelection?: boolean;
    selectedOfficers?: StaffOfficer[];
    onSelectionChange?: (officers: StaffOfficer[]) => void;
    searchRoute?: string;
}) {
    const multiple = !!onSelectionChange;
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
                const endpoint = searchRoute ?? route('tasks.assignee-search');
                const response = await fetch(
                    `${endpoint}${endpoint.includes('?') ? '&' : '?'}q=${encodeURIComponent(query.trim())}&purpose=${purpose}`,
                    {
                        signal: controller.signal,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    },
                );
                if (!response.ok) throw new Error('Staff search failed');
                const payload = (await response.json()) as {
                    users?: StaffOfficer[];
                    recipients?: (StaffOfficer & { name: string; assignment_target_type: string })[];
                };
                const officers =
                    payload.users ??
                    payload.recipients
                        ?.filter((item) => item.assignment_target_type === 'individual')
                        .map((item) => ({ ...item, full_name: item.name })) ??
                    [];
                if (!controller.signal.aborted)
                    setResults(officers.sort((a, b) => (a.title ?? '').localeCompare(b.title ?? '') || a.full_name.localeCompare(b.full_name)));
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
    }, [query, open, value, purpose, searchRoute]);

    const pick = (officer: StaffOfficer) => {
        if (onSelectionChange) {
            onSelectionChange(
                selectedOfficers.some((selected) => selected.id === officer.id)
                    ? selectedOfficers.filter((selected) => selected.id !== officer.id)
                    : [...selectedOfficers, officer],
            );
            return;
        }
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
                    placeholder="Search officer name, position or shorthand…"
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        if (!multiple) onSelect(null);
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
                        <div id={`${id}-list`} role="listbox" aria-multiselectable={multiple || undefined} aria-label={`${label} staff matches`}>
                            {results.map((officer, index) => (
                                <Fragment key={officer.id}>
                                    {(index === 0 || results[index - 1].title !== officer.title) && (
                                        <div className="officer-position-heading" role="presentation">
                                            {officer.title || 'Staff members'}
                                            {officer.title_shorthand ? ` (${officer.title_shorthand})` : ''} ·{' '}
                                            {results.filter((item) => item.title === officer.title).length} officers
                                        </div>
                                    )}
                                    <button
                                        type="button"
                                        role="option"
                                        aria-selected={multiple ? selectedOfficers.some((selected) => selected.id === officer.id) : active === index}
                                        id={`${id}-option-${index}`}
                                        key={officer.id}
                                        className="dropdown-item"
                                        onMouseDown={(event) => event.preventDefault()}
                                        onClick={() => pick(officer)}
                                    >
                                        <strong>
                                            {multiple && selectedOfficers.some((selected) => selected.id === officer.id) ? '✓ ' : ''}
                                            {officer.full_name}
                                        </strong>
                                        <span>{officer.title || 'Staff member'}</span>
                                        <small>{[officer.department, officer.context || officer.office].filter(Boolean).join(' / ')}</small>
                                    </button>
                                </Fragment>
                            ))}
                        </div>
                        {!loading && !failure && !results.length && <div role="status">No matching active staff. Try a name or job title.</div>}
                    </div>
                )}
            </div>
            {multiple && selectedOfficers.length > 0 && (
                <div className="selected-assignees" aria-label="Selected officers">
                    {selectedOfficers.map((officer) => (
                        <span className="selected-assignee" key={officer.id}>
                            <span>
                                <strong>{officer.full_name}</strong>
                                <small>{officer.title}</small>
                            </span>
                            <button
                                type="button"
                                aria-label={`Remove ${officer.full_name}`}
                                onClick={() => onSelectionChange?.(selectedOfficers.filter((selected) => selected.id !== officer.id))}
                            >
                                ×
                            </button>
                        </span>
                    ))}
                </div>
            )}
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
