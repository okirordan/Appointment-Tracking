import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import { Check } from '@/components/icons';
import { useForm } from '@inertiajs/react';
import ParentEntityPicker from './parent-entity-picker';
import type { EntityTypeOption, OrganizationEntity, PersonOption } from './types';

interface Props {
    entity: OrganizationEntity | null;
    entities: OrganizationEntity[];
    entityTypes: EntityTypeOption[];
    headOptions: PersonOption[];
    secretaryOptions: PersonOption[];
    defaultParentId: number | null;
    onClose: () => void;
}

export default function EntityFormModal({ entity, entities, entityTypes, headOptions, secretaryOptions, defaultParentId, onClose }: Props) {
    const form = useForm({
        name: entity?.name ?? '',
        code: entity?.code ?? '',
        type: entity?.type ?? 'department',
        parent_id: entity?.parent_id ? String(entity.parent_id) : defaultParentId ? String(defaultParentId) : '',
        description: entity?.description ?? '',
        head_user_id: entity?.head_user_id ? String(entity.head_user_id) : '',
        secretary_user_id: entity?.secretary_user_id ? String(entity.secretary_user_id) : '',
        is_top_level: entity?.is_top_level ?? defaultParentId === null,
        active: entity?.active ?? true,
        reason: '',
    });
    const excludedParentIds = entity === null ? new Set<number>() : descendantIds(entity.id, entities);
    if (entity !== null) excludedParentIds.add(entity.id);
    if (form.data.type !== 'affiliated_body') {
        entities.filter((candidate) => candidate.type === 'affiliated_body').forEach((candidate) => excludedParentIds.add(candidate.id));
    }
    const title = entity === null ? 'Add organizational entity' : `Edit ${entity.name}`;
    const save = () => {
        const options = { onSuccess: onClose, preserveScroll: true };
        if (entity === null) {
            form.post(route('admin.organization-structure.entities.store'), options);
        } else {
            form.put(route('admin.organization-structure.entities.update', entity.id), options);
        }
    };

    return (
        <Modal
            title={title}
            description="Use the real administrative relationship. A parent controls placement in the tree, not access to every descendant."
            size="wide"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="button" className="btn btn-primary" disabled={form.processing} onClick={save}>
                        <Check aria-hidden="true" /> {form.processing ? 'Saving…' : entity === null ? 'Add entity' : 'Save changes'}
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={form.errors} />
            <div className="two-col">
                <div className="field">
                    <label htmlFor="entity-name">
                        Name <span aria-hidden="true">*</span>
                    </label>
                    <input
                        id="entity-name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        required
                        autoFocus
                        aria-invalid={form.errors.name ? 'true' : undefined}
                    />
                    {form.errors.name && <div className="field-error">{form.errors.name}</div>}
                </div>
                <div className="field">
                    <label htmlFor="entity-type">
                        Entity type <span aria-hidden="true">*</span>
                    </label>
                    <select
                        id="entity-type"
                        value={form.data.type}
                        onChange={(event) => form.setData('type', event.target.value)}
                        disabled={entity?.type === 'ministry' || entity?.type === 'affiliated_body'}
                        required
                    >
                        <option value="">Select entity type</option>
                        {entity !== null && !entityTypes.some((type) => type.value === entity.type) && (
                            <option value={entity.type}>{entity.type_label}</option>
                        )}
                        {entityTypes.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <div className="two-col">
                <div className="field">
                    <label htmlFor="entity-code">Short name / code</label>
                    <input id="entity-code" value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} />
                    <div className="field-help">Optional. Use a short, recognizable administrative code.</div>
                </div>
                <div className="field">
                    <label htmlFor="entity-status">Status</label>
                    <select
                        id="entity-status"
                        value={form.data.active ? 'active' : 'inactive'}
                        onChange={(event) => form.setData('active', event.target.value === 'active')}
                    >
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <ParentEntityPicker
                entities={entities}
                value={form.data.parent_id}
                excludedIds={excludedParentIds}
                onChange={(value) => {
                    form.setData('parent_id', value);
                    form.setData('is_top_level', value === '');
                }}
                error={form.errors.parent_id}
            />
            <div className="field">
                <label htmlFor="entity-description">Description</label>
                <textarea
                    id="entity-description"
                    rows={3}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    placeholder="Briefly describe this entity's administrative purpose."
                />
            </div>
            <div className="two-col">
                <PersonSelect
                    id="entity-head"
                    label="Head of entity"
                    value={form.data.head_user_id}
                    options={headOptions}
                    onChange={(value) => form.setData('head_user_id', value)}
                />
                <PersonSelect
                    id="entity-secretary"
                    label="Secretary or administrative officer"
                    value={form.data.secretary_user_id}
                    options={secretaryOptions}
                    onChange={(value) => form.setData('secretary_user_id', value)}
                />
            </div>
            {entity !== null && (
                <div className="field">
                    <label htmlFor="entity-reason">Reason for change</label>
                    <textarea id="entity-reason" rows={2} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                </div>
            )}
        </Modal>
    );
}

function descendantIds(entityId: number, entities: OrganizationEntity[]): Set<number> {
    const ids = new Set<number>();
    let frontier = [entityId];
    while (frontier.length > 0) {
        const next = entities
            .filter((entity) => entity.id !== entityId && !ids.has(entity.id) && entity.parent_id !== null && frontier.includes(entity.parent_id))
            .map((entity) => entity.id);
        next.forEach((id) => ids.add(id));
        frontier = next;
    }

    return ids;
}

function PersonSelect({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    options: PersonOption[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="field">
            <label htmlFor={id}>{label}</label>
            <select id={id} value={value} onChange={(event) => onChange(event.target.value)}>
                <option value="">Not assigned</option>
                {options.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.name}
                        {person.title ? ` — ${person.title}` : ''}
                    </option>
                ))}
            </select>
        </div>
    );
}
