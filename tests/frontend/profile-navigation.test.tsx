import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Sidebar from '@/components/ats/sidebar';
import Topbar from '@/components/ats/topbar';

const { router, sharedProps } = vi.hoisted(() => ({
    router: {
        get: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
    },
    sharedProps: {
        auth: {
            user: {
                id: 7,
                username: 'patrick',
                full_name: 'Patrick Muinda',
                first_name: 'Patrick',
                initials: 'PM',
                title: 'C/LEIT',
                role: 'officer',
                role_label: 'Officer',
                permissions: [],
                department: { id: 4, name: 'Leadership and Instructional Technology', code: 'LEIT' },
                office_attachment: null,
                force_password_change: false,
                two_factor_enabled: false,
                work_mode: 'officer',
                can_switch_work_mode: false,
            },
        },
        nav: [],
        notifications: { unread_count: 0, items: [] },
    },
}));

vi.stubGlobal('route', (name: string) => `/${name}`);

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props}>{children}</a>,
    router,
    usePage: () => ({ props: sharedProps }),
}));

vi.mock('@/components/ats/theme-selector', () => ({
    default: () => <div>Theme selector</div>,
}));

describe('profile navigation', () => {
    it('shows only the accessible avatar in the sidebar profile area', () => {
        render(<Sidebar open={false} collapsed={false} onClose={vi.fn()} />);

        const profile = screen.getByRole('img', { name: 'Patrick Muinda profile' });

        expect(profile).toHaveTextContent('PM');
        expect(screen.queryByText('Patrick Muinda')).not.toBeInTheDocument();
        expect(screen.queryByText('C/LEIT')).not.toBeInTheDocument();
    });

    it('keeps the topbar trigger avatar-only and reveals the position in the flat dropdown', () => {
        render(<Topbar onMenuClick={vi.fn()} />);

        const trigger = screen.getByRole('button', { name: 'Open profile menu for Patrick Muinda' });
        expect(within(trigger).getByText('PM')).toBeInTheDocument();
        expect(within(trigger).queryByText('Patrick Muinda')).not.toBeInTheDocument();
        expect(within(trigger).queryByText('C/LEIT')).not.toBeInTheDocument();

        fireEvent.click(trigger);

        const menu = screen.getByRole('menu');
        expect(menu).toHaveClass('profile-dropdown-flat');
        expect(within(menu).getByText('Patrick Muinda')).toBeInTheDocument();
        expect(within(menu).getByText('C/LEIT')).toBeInTheDocument();
    });
});
