import { Search } from '@/components/icons';
import { useId, useState } from 'react';
import type { OrganizationEntity } from './types';

interface Props {
    entities: OrganizationEntity[];
    value: string;
    onChange: (value: string) => void;
    excludedIds?: Set<number>;
    label?: string;
    searchLabel?: string;
    error?: string;
}

export default function ParentEntityPicker({
    entities,
    value,
    onChange,
    excludedIds = new Set<number>(),
    label = 'Parent entity',
    searchLabel = 'Search parent entities',
    error,
}: Props) {
    const id = useId();
    const [search, setSearch] = useState('');
    const normalized = search.trim().toLocaleLowerCase();
    const options = entities.filter(
        (entity) =>
            entity.active &&
            !excludedIds.has(entity.id) &&
            (entity.id === Number(value) ||
                normalized === '' ||
                entity.name.toLocaleLowerCase().includes(normalized) ||
                entity.code?.toLocaleLowerCase().includes(normalized) ||
                entityPath(entity, entities).toLocaleLowerCase().includes(normalized)),
    );

    return (
        <div className="field organization-parent-picker">
            <label htmlFor={`${id}-search`}>{searchLabel}</label>
            <div className="organization-parent-search">
                <Search aria-hidden="true" />
                <input
                    id={`${id}-search`}
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search by name or code"
                />
            </div>
            <label htmlFor={`${id}-select`}>{label}</label>
            <select
                id={`${id}-select`}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error ? 'true' : undefined}
                aria-describedby={error ? `${id}-error` : undefined}
            >
                <option value="">No parent — top-level entity</option>
                {options.map((entity) => (
                    <option key={entity.id} value={entity.id}>
                        {entityPath(entity, entities)} ({entity.type_label})
                    </option>
                ))}
            </select>
            {error && (
                <div id={`${id}-error`} className="field-error">
                    {error}
                </div>
            )}
        </div>
    );
}

function entityPath(entity: OrganizationEntity, entities: OrganizationEntity[]): string {
    const byId = new Map(entities.map((item) => [item.id, item]));
    const names: string[] = [];
    const visited = new Set<number>();
    let cursor: OrganizationEntity | undefined = entity;
    while (cursor !== undefined && !visited.has(cursor.id)) {
        names.unshift(cursor.name);
        visited.add(cursor.id);
        cursor = cursor.parent_id === null ? undefined : byId.get(cursor.parent_id);
    }

    return names.join(' › ');
}
