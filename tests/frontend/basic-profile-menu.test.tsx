import BasicProfileMenu from '@/pages/mail/basic-profile-menu';
import { fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { full_name: 'Secretary Example', initials: 'SE', title: 'Secretary', role_label: 'Secretary' } } } }),
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { post },
}));

it('provides the Basic Mode avatar, account settings, and logout', () => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
    render(<BasicProfileMenu />);
    const trigger = screen.getByRole('button', { name: 'Open profile menu for Secretary Example' });
    fireEvent.click(trigger);
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('link', { name: 'Change password' })).toHaveAttribute('href', '/password.change');
    expect(screen.getByRole('link', { name: 'Security & two-factor' })).toHaveAttribute('href', '/security.show');
    fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));
    expect(post).toHaveBeenCalledWith('/logout');
});
