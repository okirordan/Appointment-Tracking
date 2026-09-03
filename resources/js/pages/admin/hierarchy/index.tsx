import AppShell from '@/components/ats/app-shell';
import { ChevronDown, ChevronRight, Plus, Search } from '@/components/icons';
import { useMemo, useState } from 'react';
import EntityDetails from './entity-details';
import EntityFormModal from './entity-form-modal';
import MoveEntityModal from './move-entity-modal';
import OrganizationTree, { filterOrganizationEntities } from './organization-tree';
import type { OrganizationEntity, OrganizationStructureProps } from './types';

type Dialog = 'add' | 'edit' | 'move' | null;

export default function OrganizationStructure({ entities, entityTypes, headOptions, secretaryOptions, summary }: OrganizationStructureProps) {
    const [selectedId, setSelectedId] = useState<number | null>(
        () => entities.find((entity) => entity.type === 'ministry')?.id ?? entities[0]?.id ?? null,
    );
    const [expanded, setExpanded] = useState<Set<number>>(
        () => new Set(entities.filter((entity) => entity.children_count > 0).map((entity) => entity.id)),
    );
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('all');
    const [dialog, setDialog] = useState<Dialog>(null);
    const [defaultParentId, setDefaultParentId] = useState<number | null>(null);
    const selected = entities.find((entity) => entity.id === selectedId) ?? null;
    const breadcrumb = useMemo(() => (selected ? entityBreadcrumb(selected, entities) : []), [selected, entities]);
    const visibleEntityCount = useMemo(() => filterOrganizationEntities(entities, search, typeFilter).length, [entities, search, typeFilter]);
    const allExpanded = entities.filter((entity) => entity.children_count > 0).every((entity) => expanded.has(entity.id));

    const openAdd = (parentId: number | null) => {
        setDefaultParentId(parentId);
        setDialog('add');
    };

    return (
        <AppShell title="Organization Structure">
            <header className="organization-structure-header">
                <div>
                    <span className="organization-page-kicker">System administration</span>
                    <h1>Organization Structure</h1>
                    <p>Manage every Ministry office, functional area, department, division, section and unit from one authoritative hierarchy.</p>
                </div>
                <button type="button" className="btn btn-primary" onClick={() => openAdd(selected?.id ?? null)}>
                    <Plus aria-hidden="true" /> Add Organizational Entity
                </button>
            </header>

            <dl className="organization-structure-summary" aria-label="Organization structure totals">
                <SummaryItem label="Total entities" value={summary.total} />
                <SummaryItem label="Active" value={summary.active} />
                <SummaryItem label="Top level" value={summary.top_level} />
                <SummaryItem label="External bodies" value={summary.external} />
            </dl>

            <div className="organization-structure-toolbar" aria-label="Organization tree controls">
                <div className="organization-structure-search">
                    <Search aria-hidden="true" />
                    <input
                        type="search"
                        aria-label="Search organization structure"
                        placeholder="Search by entity name, code or type"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                <label className="organization-type-filter">
                    <span>Entity type</span>
                    <select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}>
                        <option value="all">All entity types</option>
                        {entityTypes.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                        <option value="affiliated_body">Affiliated / External Body</option>
                    </select>
                </label>
                <button
                    type="button"
                    className="btn btn-ghost organization-expand-control"
                    onClick={() =>
                        setExpanded(
                            allExpanded
                                ? new Set<number>()
                                : new Set(entities.filter((entity) => entity.children_count > 0).map((entity) => entity.id)),
                        )
                    }
                >
                    {allExpanded ? <ChevronDown aria-hidden="true" /> : <ChevronRight aria-hidden="true" />}
                    {allExpanded ? 'Collapse all' : 'Expand all'}
                </button>
            </div>

            <div className="organization-structure-workspace">
                <section className="card organization-tree-panel" aria-labelledby="organization-tree-heading">
                    <header>
                        <div>
                            <h2 id="organization-tree-heading">Organization tree</h2>
                            <p>Select an entity to review its placement, leadership and attached staff.</p>
                        </div>
                        <span>
                            Showing {visibleEntityCount} of {entities.length}
                        </span>
                    </header>
                    <OrganizationTree
                        entities={entities}
                        expanded={expanded}
                        selectedId={selectedId}
                        search={search}
                        typeFilter={typeFilter}
                        onToggle={(id) =>
                            setExpanded((current) => {
                                const next = new Set(current);
                                if (next.has(id)) next.delete(id);
                                else next.add(id);
                                return next;
                            })
                        }
                        onSelect={setSelectedId}
                    />
                </section>

                {selected ? (
                    <EntityDetails
                        entity={selected}
                        breadcrumb={breadcrumb}
                        onAddChild={() => openAdd(selected.id)}
                        onEdit={() => setDialog('edit')}
                        onMove={() => setDialog('move')}
                    />
                ) : (
                    <div className="card organization-inspector-empty" role="status">
                        Select an organizational entity to view its details.
                    </div>
                )}
            </div>

            {dialog === 'add' && (
                <EntityFormModal
                    entity={null}
                    entities={entities}
                    entityTypes={entityTypes}
                    headOptions={headOptions}
                    secretaryOptions={secretaryOptions}
                    defaultParentId={defaultParentId}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'edit' && selected && (
                <EntityFormModal
                    entity={selected}
                    entities={entities}
                    entityTypes={entityTypes}
                    headOptions={headOptions}
                    secretaryOptions={secretaryOptions}
                    defaultParentId={selected.parent_id}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'move' && selected && <MoveEntityModal entity={selected} entities={entities} onClose={() => setDialog(null)} />}
        </AppShell>
    );
}

function SummaryItem({ label, value }: { label: string; value: number }) {
    return (
        <div className="organization-summary-item">
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}

function entityBreadcrumb(entity: OrganizationEntity, entities: OrganizationEntity[]): OrganizationEntity[] {
    const byId = new Map(entities.map((item) => [item.id, item]));
    const breadcrumb: OrganizationEntity[] = [];
    const visited = new Set<number>();
    let cursor: OrganizationEntity | undefined = entity;
    while (cursor !== undefined && !visited.has(cursor.id)) {
        breadcrumb.unshift(cursor);
        visited.add(cursor.id);
        cursor = cursor.parent_id === null ? undefined : byId.get(cursor.parent_id);
    }

    return breadcrumb;
}
