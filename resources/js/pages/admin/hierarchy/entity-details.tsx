import { ArrowRight, Check, Copy, Edit3, UserRound, UsersRound } from '@/components/icons';
import { useState } from 'react';
import EntityTypeBadge from './entity-type-badge';
import type { OrganizationEntity } from './types';

interface Props {
    entity: OrganizationEntity;
    breadcrumb: OrganizationEntity[];
    onEdit: () => void;
    onMove: () => void;
    onAddChild: () => void;
}

export default function EntityDetails({ entity, breadcrumb, onEdit, onMove, onAddChild }: Props) {
    const [copiedCode, setCopiedCode] = useState<string | null>(null);
    const copied = copiedCode === entity.code;

    const copyCode = async () => {
        if (!entity.code) return;

        try {
            await navigator.clipboard.writeText(entity.code);
            setCopiedCode(entity.code);
        } catch {
            setCopiedCode(null);
        }
    };

    return (
        <aside className="organization-entity-inspector">
            <nav className="organization-breadcrumb" aria-label="Organization Structure breadcrumb">
                <ol>
                    {breadcrumb.map((item) => (
                        <li key={item.id}>{item.name}</li>
                    ))}
                </ol>
            </nav>
            <section className="organization-entity-details" aria-label={`${entity.name} details`}>
                <header>
                    <div>
                        <EntityTypeBadge type={entity.type} label={entity.type_label} />
                        <h2>{entity.name}</h2>
                        <p>{entity.description ?? 'No description has been provided for this entity.'}</p>
                    </div>
                    <span className={`organization-entity-status ${entity.active ? 'is-active' : 'is-inactive'}`}>
                        {entity.active ? 'Active' : 'Inactive'}
                    </span>
                </header>
                <dl className="organization-entity-facts">
                    <div className="organization-entity-code-fact">
                        <dt>Code</dt>
                        <dd>
                            <span className="organization-entity-code">{entity.code ?? 'Not assigned'}</span>
                            {entity.code && (
                                <button
                                    type="button"
                                    className="organization-copy-code"
                                    aria-label={`${copied ? 'Copied entity code' : 'Copy entity code'} ${entity.code}`}
                                    title={copied ? 'Copied' : 'Copy code'}
                                    onClick={copyCode}
                                >
                                    {copied ? <Check aria-hidden="true" /> : <Copy aria-hidden="true" />}
                                </button>
                            )}
                        </dd>
                    </div>
                    <div>
                        <dt>Parent entity</dt>
                        <dd>{entity.parent_name ?? 'Top level'}</dd>
                    </div>
                    <div>
                        <dt>
                            <UserRound aria-hidden="true" /> Head of entity
                        </dt>
                        <dd>{entity.head_name ?? 'Not assigned'}</dd>
                    </div>
                    <div>
                        <dt>
                            <UserRound aria-hidden="true" /> Secretary / administrator
                        </dt>
                        <dd>{entity.secretary_name ?? 'Not assigned'}</dd>
                    </div>
                    <div>
                        <dt>
                            <UsersRound aria-hidden="true" /> Staff attached
                        </dt>
                        <dd>{entity.users_count}</dd>
                    </div>
                    <div>
                        <dt>Child entities</dt>
                        <dd>{entity.children_count}</dd>
                    </div>
                </dl>
                <footer className="organization-entity-actions" role="group" aria-label="Entity actions">
                    <button type="button" className="btn btn-primary" onClick={onAddChild}>
                        Add child entity
                    </button>
                    <div className="organization-entity-secondary-actions" data-testid="secondary-entity-actions">
                        <button type="button" className="btn btn-ghost" onClick={onEdit}>
                            <Edit3 aria-hidden="true" /> Edit entity
                        </button>
                        {entity.type !== 'ministry' && entity.type !== 'affiliated_body' && (
                            <button type="button" className="btn btn-ghost" onClick={onMove}>
                                <ArrowRight aria-hidden="true" /> Change parent
                            </button>
                        )}
                    </div>
                </footer>
            </section>
        </aside>
    );
}
