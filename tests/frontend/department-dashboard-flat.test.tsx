import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import AdminDashboard from '@/pages/dashboards/admin';
import DepartmentDashboard from '@/pages/dashboards/department';
import ExecutiveDashboard from '@/pages/dashboards/executive';
import OfficerDashboard from '@/pages/dashboards/officer';

vi.stubGlobal('route', (name: string) => `/${name}`);

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props}>{children}</a>,
    router: { get: vi.fn() },
    usePage: () => ({
        props: {
            auth: {
                user: {
                    full_name: 'Patrick Muinda',
                    title: 'C/LEIT',
                    role_label: 'Officer',
                    department: { name: 'Leadership and Instructional Technology' },
                },
            },
        },
    }),
}));

vi.mock('@/components/ats/app-shell', () => ({
    default: ({ appearance, children }: { appearance?: string; children: React.ReactNode }) => (
        <main data-testid="app-shell" data-appearance={appearance}>
            {children}
        </main>
    ),
}));

describe('Role dashboard flat design', () => {
    it('opts the page into the flat shell and register-like dashboard surface', () => {
        render(
            <DepartmentDashboard
                stats={{ total: 12, completed: 5, overdue: 2, active: 5 }}
                overdue={[]}
                recent={[]}
                status_breakdown={[{ label: 'Active', count: 5, pct: 42 }]}
                departmentName="Leadership and Instructional Technology"
                canCreate={false}
            />,
        );

        expect(screen.getByTestId('app-shell')).toHaveAttribute('data-appearance', 'flat');
        expect(screen.getByRole('heading', { name: 'Department Work' }).closest('.flat-dashboard')).toBeInTheDocument();
    });

    it('applies the flat register shell to the Permanent Secretary dashboard', () => {
        render(
            <ExecutiveDashboard
                stats={{ total: 14, completed: 8, overdue: 1, active: 5, awaiting_review: 2 }}
                stale={[]}
                department_performance={[]}
                canCreate={true}
                canDrillDownDepartmentPerformance={true}
            />,
        );

        expect(screen.getByTestId('app-shell')).toHaveAttribute('data-appearance', 'flat');
        expect(screen.getByRole('heading', { name: 'Executive Dashboard' }).closest('.flat-dashboard')).toBeInTheDocument();
    });

    it('applies the flat register shell to the officer dashboard', () => {
        render(<OfficerDashboard stats={{ total: 7, completed: 3, overdue: 1, active: 3 }} upcoming={[]} />);

        expect(screen.getByTestId('app-shell')).toHaveAttribute('data-appearance', 'flat');
        expect(screen.getByRole('heading', { name: 'My Dashboard' }).closest('.flat-dashboard')).toBeInTheDocument();
    });

    it('applies the flat register shell to the system administration dashboard', () => {
        const emptyPage = { data: [], meta: { current_page: 1, last_page: 1, total: 0 } };

        render(
            <AdminDashboard
                stats={{ total_users: 9, active_users: 8, departments: 4, tasks: 21 }}
                recent_activity={emptyPage}
                departments={emptyPage}
            />,
        );

        expect(screen.getByTestId('app-shell')).toHaveAttribute('data-appearance', 'flat');
        expect(screen.getByRole('heading', { name: 'Admin Dashboard' }).closest('.flat-dashboard')).toBeInTheDocument();
    });
});
