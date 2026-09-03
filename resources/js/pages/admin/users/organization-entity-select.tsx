import { ShieldCheck } from '@/components/icons';
import { useMemo } from 'react';

export interface StaffOrganizationOption {
    id: number;
    parent_id: number | null;
    name: string;
    type: string;
    type_label: string;
    path: string;
    department_entity_id: number | null;
    division_entity_id: number | null;
}

const primaryPlacementTypes = new Set(['department', 'office', 'regional_office', 'section']);

function closestPrimaryPlacement(
    entity: StaffOrganizationOption | null,
    entitiesById: Map<number, StaffOrganizationOption>,
): StaffOrganizationOption | null {
    const visited = new Set<number>();
    let cursor = entity;

    while (cursor && !visited.has(cursor.id)) {
        if (primaryPlacementTypes.has(cursor.type)) {
            return cursor;
        }

        visited.add(cursor.id);
        cursor = cursor.parent_id === null ? null : (entitiesById.get(cursor.parent_id) ?? null);
    }

    return null;
}

interface Props {
    idPrefix: string;
    options: StaffOrganizationOption[];
    value: string | number;
    onChange: (value: string, option: StaffOrganizationOption | null) => void;
    disabled?: boolean;
    error?: string;
    allowUnassigned?: boolean;
}

export default function OrganizationEntitySelect({ idPrefix, options, value, onChange, disabled = false, error, allowUnassigned = false }: Props) {
    const selected = options.find((option) => String(option.id) === String(value)) ?? null;
    const entitiesById = useMemo(() => new Map(options.map((option) => [option.id, option])), [options]);
    const selectedPrimaryPlacement = closestPrimaryPlacement(selected, entitiesById);
    const selectedDepartmentId = selected?.department_entity_id ?? null;
    const selectedDivisionId = selected?.division_entity_id ?? null;
    const selectedDepartment = options.find((option) => option.id === selectedDepartmentId) ?? null;
    const selectedDivision = options.find((option) => option.id === selectedDivisionId) ?? null;
    const departments = useMemo(() => options.filter((option) => option.type === 'department'), [options]);
    const offices = useMemo(() => options.filter((option) => option.type === 'office' || option.type === 'regional_office'), [options]);
    const sections = useMemo(() => options.filter((option) => option.type === 'section'), [options]);
    const divisions = useMemo(() => {
        if (selectedDepartmentId !== null) {
            return options.filter((option) => option.type === 'division' && option.department_entity_id === selectedDepartmentId);
        }

        if (selectedPrimaryPlacement !== null) {
            return [];
        }

        return options.filter((option) => option.type === 'division');
    }, [options, selectedDepartmentId, selectedPrimaryPlacement]);
    const specificEntities = useMemo(
        () =>
            options.filter((option) => {
                if (primaryPlacementTypes.has(option.type) || option.type === 'division') {
                    return false;
                }

                if (selectedDivisionId !== null) {
                    return option.division_entity_id === selectedDivisionId;
                }

                if (selectedPrimaryPlacement !== null) {
                    return closestPrimaryPlacement(option, entitiesById)?.id === selectedPrimaryPlacement.id && option.division_entity_id === null;
                }

                return closestPrimaryPlacement(option, entitiesById) === null;
            }),
        [entitiesById, options, selectedDivisionId, selectedPrimaryPlacement],
    );
    const selectedSpecificId = selected && !primaryPlacementTypes.has(selected.type) && selected.type !== 'division' ? String(selected.id) : '';
    const helpId = `${idPrefix}-organization-help`;
    const errorId = `${idPrefix}-organization-error`;

    const choose = (rawValue: string, fallback: StaffOrganizationOption | null = null) => {
        const option = options.find((item) => String(item.id) === rawValue) ?? fallback;
        onChange(option ? String(option.id) : '', option);
    };

    return (
        <div className="organization-entity-field">
            <div className="organization-entity-field__heading">
                <div>
                    <strong className="organization-entity-field__title">Organizational placement</strong>
                    <p>Set the staff member’s department, office or section and, where applicable, their division.</p>
                </div>
                <span className="organization-entity-field__security">
                    <ShieldCheck aria-hidden="true" /> Access boundary
                </span>
            </div>
            <div className="organization-entity-field__fields">
                <div className="organization-entity-field__control">
                    <label htmlFor={`${idPrefix}-department`}>Department, office or section</label>
                    <select
                        id={`${idPrefix}-department`}
                        value={selectedPrimaryPlacement?.id ?? ''}
                        disabled={disabled}
                        aria-invalid={Boolean(error)}
                        aria-describedby={`${helpId}${error ? ` ${errorId}` : ''}`}
                        onChange={(event) => choose(event.target.value)}
                    >
                        <option value="">{allowUnassigned ? 'No placement / system-wide' : 'Select a department, office or section'}</option>
                        {departments.length > 0 && (
                            <optgroup label="Departments">
                                {departments.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                        {offices.length > 0 && (
                            <optgroup label="Offices">
                                {offices.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                        {sections.length > 0 && (
                            <optgroup label="Sections">
                                {sections.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </optgroup>
                        )}
                    </select>
                </div>
                <div className="organization-entity-field__control">
                    <label htmlFor={`${idPrefix}-division`}>Division</label>
                    <select
                        id={`${idPrefix}-division`}
                        value={selectedDivisionId ?? ''}
                        disabled={disabled || divisions.length === 0}
                        aria-describedby={helpId}
                        onChange={(event) => choose(event.target.value, selectedPrimaryPlacement ?? selectedDepartment)}
                    >
                        <option value="">{divisions.length === 0 ? 'Not applicable' : 'No division / use primary placement'}</option>
                        {divisions.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.name}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            {specificEntities.length > 0 && (
                <div className="organization-entity-field__control">
                    <label htmlFor={`${idPrefix}-specific-entity`}>Unit or functional area</label>
                    <select
                        id={`${idPrefix}-specific-entity`}
                        value={selectedSpecificId}
                        disabled={disabled}
                        aria-describedby={helpId}
                        onChange={(event) => choose(event.target.value, selectedDivision ?? selectedPrimaryPlacement ?? selectedDepartment)}
                    >
                        <option value="">
                            {selectedDivision ? 'Use selected division' : selectedPrimaryPlacement ? 'Use primary placement' : 'Select an entity'}
                        </option>
                        {specificEntities.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.name} ({option.type_label})
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <div id={helpId} className="organization-entity-field__help">
                <ShieldCheck aria-hidden="true" />
                <span>
                    This placement controls the records and work this staff member can access. Secretaries are restricted to the selected entity.
                </span>
            </div>
            {selected && (
                <p className="organization-entity-field__boundary" aria-live="polite">
                    <strong>Current access boundary:</strong> {selected.path} ({selected.type_label})
                </p>
            )}
            {error && (
                <div id={errorId} className="field-error">
                    {error}
                </div>
            )}
        </div>
    );
}
