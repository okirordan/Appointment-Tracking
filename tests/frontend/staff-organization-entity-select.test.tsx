import OrganizationEntitySelect, { type StaffOrganizationOption } from '@/pages/admin/users/organization-entity-select';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

const options: StaffOrganizationOption[] = [
    {
        id: 10,
        parent_id: null,
        name: 'Higher Education',
        type: 'department',
        type_label: 'Department',
        path: 'Ministry of Education and Sports › Higher Education',
        department_entity_id: 10,
        division_entity_id: null,
    },
    {
        id: 12,
        parent_id: 10,
        name: 'University Education Division',
        type: 'division',
        type_label: 'Division',
        path: 'Ministry of Education and Sports › Higher Education › University Education Division',
        department_entity_id: 10,
        division_entity_id: 12,
    },
    {
        id: 13,
        parent_id: 10,
        name: 'Scholarships Unit',
        type: 'unit',
        type_label: 'Unit',
        path: 'Ministry of Education and Sports › Higher Education › Scholarships Unit',
        department_entity_id: 10,
        division_entity_id: null,
    },
    {
        id: 14,
        parent_id: null,
        name: 'Office of the Minister of Education and Sports',
        type: 'office',
        type_label: 'Office',
        path: 'Ministry of Education and Sports › Office of the Minister of Education and Sports',
        department_entity_id: null,
        division_entity_id: null,
    },
    {
        id: 15,
        parent_id: null,
        name: 'Records Management Section',
        type: 'section',
        type_label: 'Section',
        path: 'Ministry of Education and Sports › Records Management Section',
        department_entity_id: null,
        division_entity_id: null,
    },
];

describe('Staff organization entity selector', () => {
    it('shows compact linked department and division placement fields', () => {
        render(<OrganizationEntitySelect idPrefix="staff" options={options} value="10" onChange={vi.fn()} />);

        expect(screen.getByRole('combobox', { name: 'Department, office or section' })).toHaveValue('10');
        expect(screen.getByRole('combobox', { name: 'Division' })).toHaveValue('');
        expect(screen.getByRole('option', { name: 'University Education Division' })).toBeInTheDocument();
        expect(screen.getByText(/controls the records and work this staff member can access/i)).toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Entity level' })).not.toBeInTheDocument();
    });

    it('offers offices and sections in the primary placement dropdown and assigns them exactly', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<OrganizationEntitySelect idPrefix="profile" options={options} value="14" onChange={onChange} />);

        const primaryPlacement = screen.getByRole('combobox', { name: 'Department, office or section' });

        expect(primaryPlacement).toHaveValue('14');
        expect(within(primaryPlacement).getByRole('option', { name: 'Office of the Minister of Education and Sports' })).toBeInTheDocument();
        expect(within(primaryPlacement).getByRole('option', { name: 'Records Management Section' })).toBeInTheDocument();

        await user.selectOptions(primaryPlacement, '15');

        expect(onChange).toHaveBeenCalledWith('15', options[4]);
    });

    it('assigns the selected division as the exact organizational entity', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<OrganizationEntitySelect idPrefix="profile" options={options} value="10" onChange={onChange} />);

        await user.selectOptions(screen.getByRole('combobox', { name: 'Division' }), '12');

        expect(onChange).toHaveBeenCalledWith('12', options[1]);
    });
});
