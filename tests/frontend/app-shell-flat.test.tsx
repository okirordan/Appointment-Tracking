import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import AppShell from '@/components/ats/app-shell';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

vi.mock('@/components/ats/sidebar', () => ({
    default: () => <aside aria-label="Application sidebar" />,
}));

vi.mock('@/components/ats/topbar', () => ({
    default: () => <header aria-label="Application toolbar" />,
}));

vi.mock('@/components/ats/flash-bridge', () => ({
    default: () => null,
}));

vi.mock('@/components/ats/temp-credential-modal', () => ({
    default: () => null,
}));

vi.mock('@/lib/sidebar', () => ({
    getSidebarCollapsed: () => false,
    setSidebarCollapsed: vi.fn(),
}));

describe('AppShell flat appearance', () => {
    it('marks the whole shell as flat so the page and sidebar share one surface language', () => {
        render(
            <AppShell appearance="flat" title="Mail Register">
                <p>Mail register content</p>
            </AppShell>,
        );

        expect(screen.getByText('Mail register content').closest('.ats')).toHaveClass('ats-flat');
    });
});
