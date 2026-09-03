import { Building2, ChevronDown, ChevronRight, Network } from '@/components/icons';
import type { ReactNode } from 'react';
import EntityTypeBadge from './entity-type-badge';
import type { OrganizationEntity } from './types';

interface Props {
    entities: OrganizationEntity[];
    expanded: Set<number>;
    selectedId: number | null;
    search: string;
    typeFilter: string;
    onToggle: (id: number) => void;
    onSelect: (id: number) => void;
}

export default function OrganizationTree({ entities, expanded, selectedId, search, typeFilter, onToggle, onSelect }: Props) {
    const isFiltering = search.trim() !== '' || typeFilter !== 'all';
    const filtered = filterOrganizationEntities(entities, search, typeFilter);

    if (filtered.length === 0) {
        return (
            <div className="organization-tree-empty" role="status">
                <Network aria-hidden="true" />
                <strong>No matching entities</strong>
                <span>Try a broader name, code or entity type.</span>
            </div>
        );
    }

    if (isFiltering) {
        return (
            <div className="organization-tree" role="tree" aria-label="Ministry organization tree">
                {filtered.map((entity) => (
                    <div key={entity.id} role="treeitem" aria-level={1}>
                        <TreeRow entity={entity} level={1} selected={selectedId === entity.id} onSelect={onSelect} />
                    </div>
                ))}
            </div>
        );
    }

    const childrenByParent = new Map<number | null, OrganizationEntity[]>();
    for (const entity of entities) {
        const siblings = childrenByParent.get(entity.parent_id) ?? [];
        siblings.push(entity);
        childrenByParent.set(entity.parent_id, siblings);
    }
    for (const siblings of childrenByParent.values()) {
        siblings.sort((left, right) => left.sort_order - right.sort_order || left.name.localeCompare(right.name));
    }

    const renderBranch = (entity: OrganizationEntity, level: number): ReactNode => {
        const children = childrenByParent.get(entity.id) ?? [];
        const open = expanded.has(entity.id);

        return (
            <div key={entity.id} role="treeitem" aria-level={level} aria-expanded={children.length > 0 ? open : undefined}>
                <TreeRow
                    entity={entity}
                    level={level}
                    selected={selectedId === entity.id}
                    expanded={open}
                    hasChildren={children.length > 0}
                    onToggle={onToggle}
                    onSelect={onSelect}
                />
                {children.length > 0 && open && (
                    <div className="organization-tree-children" data-parent-level={level} role="group">
                        {children.map((child) => renderBranch(child, level + 1))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <div className="organization-tree" role="tree" aria-label="Ministry organization tree">
            {(childrenByParent.get(null) ?? []).map((entity) => renderBranch(entity, 1))}
        </div>
    );
}

export function filterOrganizationEntities(entities: OrganizationEntity[], search: string, typeFilter: string): OrganizationEntity[] {
    const normalizedSearch = search.trim().toLocaleLowerCase();

    return entities.filter(
        (entity) =>
            (typeFilter === 'all' || entity.type === typeFilter) &&
            (normalizedSearch === '' ||
                entity.name.toLocaleLowerCase().includes(normalizedSearch) ||
                entity.code?.toLocaleLowerCase().includes(normalizedSearch) ||
                entity.type_label.toLocaleLowerCase().includes(normalizedSearch)),
    );
}

function TreeRow({
    entity,
    level,
    selected,
    expanded = false,
    hasChildren = false,
    onToggle,
    onSelect,
}: {
    entity: OrganizationEntity;
    level: number;
    selected: boolean;
    expanded?: boolean;
    hasChildren?: boolean;
    onToggle?: (id: number) => void;
    onSelect: (id: number) => void;
}) {
    return (
        <div className={`organization-tree-row${selected ? 'is-selected' : ''}${!entity.active ? 'is-inactive' : ''}`} data-level={level}>
            {hasChildren ? (
                <button
                    type="button"
                    className="organization-tree-toggle"
                    aria-label={`${expanded ? 'Collapse' : 'Expand'} ${entity.name}`}
                    onClick={() => onToggle?.(entity.id)}
                >
                    {expanded ? <ChevronDown aria-hidden="true" /> : <ChevronRight aria-hidden="true" />}
                </button>
            ) : (
                <span className="organization-tree-toggle-spacer" aria-hidden="true" />
            )}
            <button
                type="button"
                className="organization-tree-entity"
                aria-label={`${entity.name}, ${entity.type_label}`}
                aria-current={selected ? 'true' : undefined}
                onClick={() => onSelect(entity.id)}
            >
                <span className={`organization-tree-icon type-${entity.type}`} aria-hidden="true">
                    {entity.type === 'ministry' || entity.type === 'functional_area' ? <Network /> : <Building2 />}
                </span>
                <span className="organization-tree-copy">
                    <strong>{entity.name}</strong>
                    <span>
                        {entity.code ?? 'No code'} · {entity.users_count} staff
                    </span>
                </span>
                <EntityTypeBadge type={entity.type} label={entity.type_label} />
                {!entity.active && <span className="organization-status-badge">Inactive</span>}
            </button>
        </div>
    );
}
