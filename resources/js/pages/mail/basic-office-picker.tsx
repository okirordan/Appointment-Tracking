import { useEffect, useState } from 'react';

export type BasicOffice = { key: string; value: string; label: string; kind: string };

export default function BasicOfficePicker({
    id,
    value,
    onChange,
    endpoint,
    placeholder = 'Search a title or enter an office',
    source = false,
    allowNewRecipient = false,
    required = true,
}: {
    id: string;
    value: string;
    onChange: (value: string, key: string) => void;
    endpoint: string;
    placeholder?: string;
    source?: boolean;
    allowNewRecipient?: boolean;
    required?: boolean;
}) {
    const [options, setOptions] = useState<BasicOffice[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const [failed, setFailed] = useState(false);
    const [resolvedQuery, setResolvedQuery] = useState('');
    useEffect(() => {
        setOptions([]);
        setActive(-1);
        setResolvedQuery('');
        setFailed(false);
        if (!open || !value.trim()) return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('q', value.trim());
            url.searchParams.set('linked', '1');
            if (source) url.searchParams.set('purpose', 'source');
            fetch(url.pathname + url.search, { signal: controller.signal, headers: { Accept: 'application/json' } })
                .then((response) => {
                    if (!response.ok) throw new Error('Directory unavailable');
                    return response.json();
                })
                .then((data: { offices: BasicOffice[] }) => {
                    if (!controller.signal.aborted) {
                        setOptions(data.offices);
                        setResolvedQuery(value.trim().replace(/\s+/g, ' '));
                        setFailed(false);
                    }
                })
                .catch((error: unknown) => {
                    if (!controller.signal.aborted && !(error instanceof DOMException && error.name === 'AbortError')) setFailed(true);
                });
        }, 200);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [endpoint, open, source, value]);
    const search = value.trim().replace(/\s+/g, ' ');
    const normalizedSearch = search.toLocaleLowerCase();
    const hasExactMatch = options.some((option) =>
        [option.value, ...option.label.split(' — ')].some((part) => part.trim().replace(/\s+/g, ' ').toLocaleLowerCase() === normalizedSearch),
    );
    const canAdd = search && resolvedQuery === search && !failed && !hasExactMatch;
    const choices =
        canAdd && source
            ? [...options, { key: 'new', value: search, label: `Add New Source: ${search}`, kind: 'Custom source' }]
            : canAdd && allowNewRecipient
              ? [...options, { key: 'new-recipient', value: search, label: `Add New Recipient: ${search}`, kind: 'Custom recipient' }]
              : options;
    const select = (option: BasicOffice) => {
        onChange(option.value, option.key);
        setOpen(false);
    };
    return (
        <div
            className="basic-office-picker"
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false);
            }}
        >
            <input
                id={id}
                required={required}
                maxLength={255}
                placeholder={placeholder}
                value={value}
                role="combobox"
                aria-autocomplete="list"
                aria-expanded={open && choices.length > 0}
                aria-controls={`${id}-options`}
                aria-activedescendant={active >= 0 && open ? `${id}-option-${active}` : undefined}
                onFocus={() => setOpen(true)}
                onChange={(event) => {
                    onChange(event.target.value, '');
                    setOpen(true);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') setOpen(false);
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        setOpen(true);
                        setActive((current) =>
                            choices.length ? (current + (event.key === 'ArrowDown' ? 1 : -1) + choices.length) % choices.length : -1,
                        );
                    }
                    if (event.key === 'Enter' && open && active >= 0 && choices[active]) {
                        event.preventDefault();
                        select(choices[active]);
                    }
                }}
            />
            {open && choices.length > 0 && (
                <ul id={`${id}-options`} role="listbox" className="basic-office-options">
                    {choices.map((option, index) => (
                        <li
                            key={`${option.key}:${option.label}`}
                            role="option"
                            id={`${id}-option-${index}`}
                            aria-selected={index === active}
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => select(option)}
                        >
                            <strong>{option.label}</strong>
                            <small>{option.kind}</small>
                        </li>
                    ))}
                </ul>
            )}
            {open && failed && <small role="status">Directory unavailable. You can retry or enter a custom office.</small>}
        </div>
    );
}
