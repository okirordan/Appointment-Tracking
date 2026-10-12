import NotificationSettings from '@/pages/notifications/settings';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({ mode: 'basic' }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { mailMode: state.mode } }),
    useForm: (data: Record<string, boolean>) => ({ data, errors: {}, processing: false, setData: vi.fn(), put: vi.fn() }),
}));
vi.mock('@/components/ats/app-shell', () => ({ default: ({ children }: { children: ReactNode }) => <div data-testid="full-shell">{children}</div> }));
vi.mock('@/pages/mail/basic-settings-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <div data-testid="basic-shell">{children}</div>,
}));
vi.mock('@/pages/mail/mode-preferences-panel', () => ({ default: () => <div>Mode Preferences</div> }));

const preferences = {
    in_app_enabled: true,
    browser_enabled: false,
    email_enabled: false,
    new_assignments: true,
    assignment_views: true,
    deadline_reminders: true,
    completion_notifications: true,
    correspondence_updates: true,
    annotation_updates: true,
    office_correspondence: true,
};
const props = {
    preferences,
    permissionDeniedBefore: false,
    activeDeviceCount: 0,
    vapidPublicKey: '',
    pushConfigured: false,
    emailConfigured: false,
    userEmail: null,
};

it('keeps Settings in the Basic shell while Basic Mode is active', () => {
    state.mode = 'basic';
    const { unmount } = render(<NotificationSettings {...props} />);
    expect(screen.getByTestId('basic-shell')).toBeInTheDocument();
    expect(screen.queryByTestId('full-shell')).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Settings' })).toBeInTheDocument();
    unmount();

    state.mode = 'full';
    render(<NotificationSettings {...props} />);
    expect(screen.getByTestId('full-shell')).toBeInTheDocument();
    expect(screen.queryByTestId('basic-shell')).not.toBeInTheDocument();
});
