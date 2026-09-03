import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import { ArrowRight } from '@/components/icons';
import { useForm } from '@inertiajs/react';
import ParentEntityPicker from './parent-entity-picker';
import type { OrganizationEntity } from './types';

export default function MoveEntityModal({
    entity,
    entities,
    onClose,
}: {
    entity: OrganizationEntity;
    entities: OrganizationEntity[];
    onClose: () => void;
}) {
    const excludedIds = descendantIds(entity.id, entities);
    excludedIds.add(entity.id);
    if (entity.type !== 'affiliated_body') {
        entities.filter((candidate) => candidate.type === 'affiliated_body').forEach((candidate) => excludedIds.add(candidate.id));
    }
    const form = useForm({
        parent_id: entity.parent_id ? String(entity.parent_id) : '',
        is_top_level: entity.is_top_level,
        reason: '',
    });

    return (
        <Modal
            title={`Move ${entity.name}`}
            description={
                <>
                    Current parent: <strong>{entity.parent_name ?? 'Top level'}</strong>. Existing correspondence and assignments remain attached to
                    this entity.
                </>
            }
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary"
                        disabled={form.processing}
                        onClick={() =>
                            form.patch(route('admin.organization-structure.entities.move', entity.id), { onSuccess: onClose, preserveScroll: true })
                        }
                    >
                        <ArrowRight aria-hidden="true" /> {form.processing ? 'Moving…' : 'Move entity'}
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={form.errors} />
            <ParentEntityPicker
                entities={entities}
                value={form.data.parent_id}
                excludedIds={excludedIds}
                label="New parent entity"
                searchLabel="Search possible parents"
                onChange={(value) => {
                    form.setData('parent_id', value);
                    form.setData('is_top_level', value === '');
                }}
                error={form.errors.parent_id}
            />
            <div className="field">
                <label htmlFor="move-reason">
                    Reason for change <span aria-hidden="true">*</span>
                </label>
                <textarea
                    id="move-reason"
                    rows={3}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    required
                    aria-invalid={form.errors.reason ? 'true' : undefined}
                />
                {form.errors.reason && <div className="field-error">{form.errors.reason}</div>}
            </div>
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
