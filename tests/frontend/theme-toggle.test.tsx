import ThemeSelector from '@/components/ats/theme-selector';
import { THEME_STORAGE_KEY } from '@/lib/theme';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';

afterEach(() => vi.unstubAllGlobals());

it('shows one next-mode icon and persists all three modes across remounts', async () => {
    localStorage.setItem(THEME_STORAGE_KEY, 'light');
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    const user = userEvent.setup();
    const view = render(<ThemeSelector />);
    expect(screen.getAllByRole('button')).toHaveLength(1);
    await user.click(screen.getByRole('button', { name: 'Switch to Dark appearance' }));
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');
    view.unmount();
    render(<ThemeSelector />);
    await user.click(screen.getByRole('button', { name: 'Switch to System appearance' }));
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('system');
    await user.click(screen.getByRole('button', { name: 'Switch to Light appearance' }));
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('light');
    expect(screen.getAllByRole('button')).toHaveLength(1);
});
