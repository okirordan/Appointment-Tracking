import BasicSettingsShell from '@/pages/mail/basic-settings-shell';
import { fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@/components/ats/flash-bridge', () => ({ default: () => null }));
vi.mock('@/components/ats/impersonation-banner', () => ({ default: () => null }));
vi.mock('@/components/ats/theme-selector', () => ({ default: () => null }));
vi.mock('@/pages/mail/mode-experience', () => ({ default: () => null }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    usePage: () => ({
        url: '/notification-settings?mode=basic',
        props: {
            mailMode: 'basic',
            canSwitchMailMode: true,
            modePreferences: null,
            auth: { user: { full_name: 'Secretary Example', initials: 'SE', title: 'Secretary' } },
        },
    }),
    router: { post: vi.fn() },
}));

it('renders Settings inside the existing Basic header, navigation, and profile menu', () => {
    vi.stubGlobal('route', (name: string, params?: Record<string, string>) => `/${name}${params?.mode ? `?mode=${params.mode}` : ''}`);
    const { container } = render(
        <BasicSettingsShell title="Notification settings">
            <h1>Settings</h1>
        </BasicSettingsShell>,
    );
    expect(container.querySelector('.mail-basic')).toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: 'Mail navigation' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Incoming Mail' })).toHaveAttribute('href', '/mail.incoming.index?mode=basic');
    expect(screen.getByRole('heading', { name: 'Settings' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Open profile menu for Secretary Example' }));
    expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute('href', '/notifications.settings?mode=basic');
    vi.unstubAllGlobals();
});
