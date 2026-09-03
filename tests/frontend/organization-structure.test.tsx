import { ConfirmProvider } from '@/hooks/use-confirm';
import OrganizationStructure from '@/pages/admin/hierarchy';
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), put: vi.fn(), patch: vi.fn() },
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        processing: false,
        setData: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        reset: vi.fn(),
    }),
}));
vi.mock('@/components/ats/app-shell', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const props = {
    entities: [
        {
            id: 1,
            name: 'Ministry of Education and Sports',
            code: 'MOES',
            type: 'ministry',
            type_label: 'Ministry',
            parent_id: null,
            parent_name: null,
            description: 'The authoritative Ministry structure.',
            head_user_id: null,
            head_name: null,
            secretary_user_id: null,
            secretary_name: null,
            active: true,
            is_top_level: true,
            sort_order: 0,
            children_count: 1,
            users_count: 0,
        },
        {
            id: 2,
            name: 'Education Administration and Training',
            code: 'EAT',
            type: 'functional_area',
            type_label: 'Functional Area',
            parent_id: 1,
            parent_name: 'Ministry of Education and Sports',
            description: null,
            head_user_id: null,
            head_name: null,
            secretary_user_id: null,
            secretary_name: null,
            active: true,
            is_top_level: false,
            sort_order: 0,
            children_count: 1,
            users_count: 0,
        },
        {
            id: 3,
            name: 'Department of Higher Education',
            code: 'ORG-HE',
            type: 'department',
            type_label: 'Department',
            parent_id: 2,
            parent_name: 'Education Administration and Training',
            description: null,
            head_user_id: 10,
            head_name: 'Commissioner Higher Education',
            secretary_user_id: 11,
            secretary_name: 'Higher Education Secretary',
            active: true,
            is_top_level: false,
            sort_order: 0,
            children_count: 1,
            users_count: 14,
        },
        {
            id: 4,
            name: 'University Education Division',
            code: 'ORG-UED',
            type: 'division',
            type_label: 'Division',
            parent_id: 3,
            parent_name: 'Department of Higher Education',
            description: null,
            head_user_id: null,
            head_name: null,
            secretary_user_id: null,
            secretary_name: null,
            active: true,
            is_top_level: false,
            sort_order: 0,
            children_count: 1,
            users_count: 4,
        },
        {
            id: 5,
            name: 'Scholarships Unit',
            code: 'ORG-SU',
            type: 'unit',
            type_label: 'Unit',
            parent_id: 4,
            parent_name: 'University Education Division',
            description: null,
            head_user_id: null,
            head_name: null,
            secretary_user_id: null,
            secretary_name: null,
            active: true,
            is_top_level: false,
            sort_order: 0,
            children_count: 0,
            users_count: 2,
        },
    ],
    entityTypes: [
        { value: 'office', label: 'Office' },
        { value: 'functional_area', label: 'Functional Area' },
        { value: 'department', label: 'Department' },
        { value: 'division', label: 'Division' },
        { value: 'section', label: 'Section' },
        { value: 'unit', label: 'Unit' },
        { value: 'regional_office', label: 'Regional Office' },
    ],
    headOptions: [{ id: 10, name: 'Commissioner Higher Education', title: 'Commissioner' }],
    secretaryOptions: [{ id: 11, name: 'Higher Education Secretary', title: 'Personal Secretary' }],
    summary: { total: 5, active: 5, top_level: 1, external: 0 },
};

describe('Organization Structure administration', () => {
    it('presents one searchable expandable tree without legacy position or reporting controls', async () => {
        const user = userEvent.setup();
        render(
            <ConfirmProvider>
                <OrganizationStructure {...props} />
            </ConfirmProvider>,
        );

        expect(screen.getByRole('heading', { name: 'Organization Structure', level: 1 })).toBeInTheDocument();
        expect(screen.getByRole('tree', { name: 'Ministry organization tree' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add Organizational Entity' })).toBeInTheDocument();
        expect(screen.getByRole('searchbox', { name: 'Search organization structure' })).toBeInTheDocument();
        expect(screen.queryByText('Positions and reporting route')).not.toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: 'Departments' })).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Department of Higher Education, Department' }));
        const details = screen.getByRole('region', { name: 'Department of Higher Education details' });
        expect(within(details).getByText('Commissioner Higher Education')).toBeInTheDocument();
        expect(within(details).getByText('Higher Education Secretary')).toBeInTheDocument();
        expect(screen.getByLabelText('Organization Structure breadcrumb')).toHaveTextContent(
            'Ministry of Education and SportsEducation Administration and TrainingDepartment of Higher Education',
        );

        await user.type(screen.getByRole('searchbox', { name: 'Search organization structure' }), 'higher education');
        expect(screen.getByRole('button', { name: 'Department of Higher Education, Department' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Education Administration and Training, Functional Area/ })).not.toBeInTheDocument();
        expect(screen.getByText('Showing 1 of 5')).toBeInTheDocument();
    });

    it('renders deeply nested branches with consistent toggles, spacers and shared type badges', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <ConfirmProvider>
                <OrganizationStructure {...props} />
            </ConfirmProvider>,
        );

        expect(screen.getByRole('button', { name: 'Collapse Ministry of Education and Sports' })).toHaveClass('organization-tree-toggle');
        expect(screen.getByRole('button', { name: 'Collapse Department of Higher Education' })).toHaveClass('organization-tree-toggle');
        expect(container.querySelectorAll('.organization-tree-children')).toHaveLength(4);
        expect(container.querySelector('.organization-tree-row[data-level="5"]')).toBeInTheDocument();
        expect(container.querySelectorAll('.organization-tree-toggle-spacer')).toHaveLength(1);

        await user.click(screen.getByRole('button', { name: 'Collapse Ministry of Education and Sports' }));
        expect(screen.queryByRole('button', { name: 'Department of Higher Education, Department' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Expand Ministry of Education and Sports' }));

        await user.click(screen.getByRole('button', { name: 'Department of Higher Education, Department' }));
        const departmentBadges = screen.getAllByText('Department').filter((element) => element.matches('[data-entity-type="department"]'));
        expect(departmentBadges).toHaveLength(2);
    });

    it('groups inspector actions and gives the entity code its dedicated copy treatment', async () => {
        const user = userEvent.setup();
        render(
            <ConfirmProvider>
                <OrganizationStructure {...props} />
            </ConfirmProvider>,
        );

        await user.click(screen.getByRole('button', { name: 'Department of Higher Education, Department' }));
        const details = screen.getByRole('region', { name: 'Department of Higher Education details' });
        expect(within(details).getByText('ORG-HE')).toHaveClass('organization-entity-code');
        expect(within(details).getByRole('button', { name: 'Copy entity code ORG-HE' })).toBeInTheDocument();
        expect(within(details).getByRole('group', { name: 'Entity actions' })).toBeInTheDocument();
        expect(within(details).getByTestId('secondary-entity-actions')).toContainElement(
            within(details).getByRole('button', { name: 'Change parent' }),
        );
    });

    it('uses one minimal entity form with a searchable parent selector', async () => {
        const user = userEvent.setup();
        render(
            <ConfirmProvider>
                <OrganizationStructure {...props} />
            </ConfirmProvider>,
        );

        await user.click(screen.getByRole('button', { name: 'Add Organizational Entity' }));
        const dialog = screen.getByRole('dialog', { name: 'Add organizational entity' });
        expect(within(dialog).getByRole('textbox', { name: 'Name' })).toBeRequired();
        expect(within(dialog).getByRole('combobox', { name: 'Entity type' })).toBeRequired();
        expect(within(dialog).getByRole('searchbox', { name: 'Search parent entities' })).toBeInTheDocument();
        expect(within(dialog).getByRole('combobox', { name: 'Parent entity' })).toBeInTheDocument();
        expect(within(dialog).getByRole('combobox', { name: 'Head of entity' })).toBeInTheDocument();
        expect(within(dialog).getByRole('combobox', { name: 'Secretary or administrative officer' })).toBeInTheDocument();
        expect(within(dialog).getByRole('combobox', { name: 'Status' })).toBeInTheDocument();
    });

    it('provides a keyboard-accessible change-parent workflow', async () => {
        const user = userEvent.setup();
        render(
            <ConfirmProvider>
                <OrganizationStructure {...props} />
            </ConfirmProvider>,
        );

        await user.click(screen.getByRole('button', { name: 'Department of Higher Education, Department' }));
        await user.click(screen.getByRole('button', { name: 'Change parent' }));
        const dialog = screen.getByRole('dialog', { name: 'Move Department of Higher Education' });
        expect(within(dialog).getByRole('combobox', { name: 'New parent entity' })).toBeInTheDocument();
        expect(within(dialog).getByRole('textbox', { name: 'Reason for change' })).toBeRequired();

        fireEvent.keyDown(within(dialog).getByRole('button', { name: 'Cancel' }), { key: 'Escape' });
    });
});
