import MailModeSwitch from '@/pages/mail/mode';
import ModeExperience from '@/pages/mail/mode-experience';
import type { ModePreferences } from '@/pages/mail/mode-preferences-api';
import ModePreferencesPanel from '@/pages/mail/mode-preferences-panel';
import ModeSelectionDialog from '@/pages/mail/mode-selection-dialog';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    page: { url: '/incoming-mail', props: {} as Record<string, unknown> },
    get: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => state.page,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { get: state.get, on: () => () => undefined },
}));

const initial: ModePreferences = {
    user_id: 12,
    active_mode: 'full',
    default_mode: 'full',
    prompt: 'welcome',
    basic_tried: false,
    tips_enabled: true,
    tips_dismissed: false,
    login_sequence: 1,
    tip_eligible: false,
};
const reply = (data: unknown) => ({ ok: true, headers: new Headers({ 'Content-Type': 'application/json' }), json: async () => data });

beforeEach(() => {
    state.page = { url: '/incoming-mail', props: { canSwitchMailMode: true, mailMode: 'full', modePreferences: { ...initial } } };
    state.get.mockClear();
    sessionStorage.clear();
    localStorage.clear();
    vi.stubGlobal('route', (name: string) => `/${name.replace('.', '/')}`);
    vi.stubGlobal('fetch', vi.fn());
});
afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Mode introduction', () => {
    it('uses accessible mode cards, both previews and benefits, and defaults the save checkbox to checked', () => {
        const choose = vi.fn();
        render(<ModeSelectionDialog preferences={initial} busy={false} error="" onChoose={choose} onLater={vi.fn()} />);
        expect(screen.getByRole('dialog', { name: 'Introducing Basic Mode!' })).toBeInTheDocument();
        expect(screen.getAllByRole('img')).toHaveLength(2);
        expect(screen.getByText('Simple, task-focused interface')).toBeVisible();
        expect(screen.getByText('Complete navigation sidebar')).toBeVisible();
        expect(screen.getByLabelText('Always open in my selected mode')).toBeChecked();
        fireEvent.click(screen.getByRole('radio', { name: /^Basic Mode/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(choose).toHaveBeenCalledWith('basic', true);
        fireEvent.click(screen.getByLabelText('Always open in my selected mode'));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(choose).toHaveBeenLastCalledWith('basic', false);
    });

    it('allows Decide Later and Escape, and keeps repeat selection simple', () => {
        const later = vi.fn();
        const choose = vi.fn();
        render(<ModeSelectionDialog preferences={{ ...initial, prompt: 'choose' }} busy={false} error="" onChoose={choose} onLater={later} />);
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Decide Later' }));
        expect(later).toHaveBeenCalledOnce();
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(later).toHaveBeenCalledTimes(2);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(choose).toHaveBeenCalledWith('full', false);
    });

    it('saves the chosen welcome mode before navigating to the existing view', async () => {
        vi.mocked(fetch).mockResolvedValue(
            reply({ preferences: { ...initial, prompt: null, active_mode: 'basic', basic_tried: true, default_mode: 'basic' } }) as Response,
        );
        render(<ModeExperience />);
        fireEvent.click(screen.getByRole('radio', { name: /^Basic Mode/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() => expect(state.get).toHaveBeenCalledWith('/incoming-mail?mode=basic'));
        expect(JSON.parse(vi.mocked(fetch).mock.calls[0][1]!.body as string)).toEqual({ mode: 'basic', save_default: true });
    });
});

describe('Preferences and switching', () => {
    it('saves Ask Me Each Time and the tips switch in the existing settings section', async () => {
        vi.mocked(fetch).mockResolvedValue(reply({ preferences: { ...initial, default_mode: 'ask', tips_enabled: false } }) as Response);
        render(<ModePreferencesPanel />);
        fireEvent.change(screen.getByLabelText('Default mode'), { target: { value: 'ask' } });
        fireEvent.click(screen.getByRole('switch', { name: 'Show occasional Basic Mode tips' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save mode preferences' }));
        await screen.findByText('Mode preferences saved.');
        expect(JSON.parse(vi.mocked(fetch).mock.calls[0][1]!.body as string)).toEqual({ default_mode: 'ask', tips_enabled: false });
        expect(state.get).not.toHaveBeenCalled();
    });

    it('protects unsaved form data when switching and hides the introductory chip after trying Basic', () => {
        state.page.props.modePreferences = { ...initial, prompt: null };
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const { rerender } = render(
            <>
                <ModeExperience />
                <MailModeSwitch />
                <form>
                    <input aria-label="Correspondence details" />
                </form>
            </>,
        );
        expect(screen.getByRole('link', { name: 'Try Basic mode' })).toBeInTheDocument();
        fireEvent.input(screen.getByLabelText('Correspondence details'), { target: { value: 'Unsaved correspondence' } });
        expect(fireEvent.click(screen.getByRole('link', { name: 'Basic' }))).toBe(false);
        expect(confirm).toHaveBeenCalledOnce();
        expect(screen.getByLabelText('Correspondence details')).toHaveValue('Unsaved correspondence');
        state.page.props.modePreferences = { ...initial, prompt: null, basic_tried: true };
        rerender(<MailModeSwitch />);
        expect(screen.queryByRole('link', { name: 'Try Basic mode' })).not.toBeInTheDocument();
        confirm.mockRestore();
    });

    it('offers an explicit Make this my default action after switching', async () => {
        state.page.props.modePreferences = { ...initial, prompt: null, active_mode: 'basic', basic_tried: true };
        sessionStorage.setItem('ats:mode-switch:12', 'basic');
        vi.mocked(fetch).mockResolvedValue(
            reply({ preferences: { ...initial, prompt: null, active_mode: 'basic', default_mode: 'basic', basic_tried: true } }) as Response,
        );
        render(<ModeExperience />);
        fireEvent.click(screen.getByRole('button', { name: 'Make this my default' }));
        await waitFor(() => expect(fetch).toHaveBeenCalledOnce());
        expect(JSON.parse(vi.mocked(fetch).mock.calls[0][1]!.body as string)).toEqual({ default_mode: 'basic' });
    });
});

describe('Occasional tips', () => {
    it('waits one second, claims once, and allows dismissal without navigating', async () => {
        vi.useFakeTimers();
        state.page.props.modePreferences = { ...initial, prompt: null, login_sequence: 2, tip_eligible: true };
        vi.mocked(fetch).mockResolvedValue(reply({ show: true }) as Response);
        render(<ModeExperience />);
        await act(async () => {
            vi.advanceTimersByTime(999);
        });
        expect(fetch).not.toHaveBeenCalled();
        await act(async () => {
            vi.advanceTimersByTime(1);
        });
        expect(screen.getByRole('complementary', { name: 'Basic Mode tip' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Remind Me Later' }));
        await act(async () => {
            vi.advanceTimersByTime(10000);
        });
        expect(fetch).toHaveBeenCalledOnce();
        expect(state.get).not.toHaveBeenCalled();
        expect(screen.queryByRole('complementary')).not.toBeInTheDocument();
    });

    it('does not display a tip claimed by another tab or over a selection dialog', async () => {
        vi.useFakeTimers();
        state.page.props.modePreferences = { ...initial, tip_eligible: true };
        vi.mocked(fetch).mockResolvedValue(reply({ show: false }) as Response);
        const { rerender } = render(<ModeExperience />);
        await act(async () => {
            vi.advanceTimersByTime(1000);
        });
        expect(fetch).not.toHaveBeenCalled();
        state.page.props.modePreferences = { ...initial, prompt: null, login_sequence: 2, tip_eligible: true };
        rerender(<ModeExperience />);
        await act(async () => {
            vi.advanceTimersByTime(1000);
        });
        expect(fetch).toHaveBeenCalledOnce();
        expect(screen.queryByRole('complementary')).not.toBeInTheDocument();
    });
});
