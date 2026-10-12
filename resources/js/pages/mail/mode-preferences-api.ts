import { csrfHeaders } from '@/lib/csrf';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { mailContextUrl, type MailMode } from './navigation';

export interface ModePreferences {
    user_id: number;
    active_mode: MailMode;
    default_mode: MailMode | 'ask';
    prompt: 'welcome' | 'choose' | null;
    basic_tried: boolean;
    tips_enabled: boolean;
    tips_dismissed: boolean;
    login_sequence: number;
    tip_eligible: boolean;
}

const updated = 'ats:mode-preferences';
export const storageKey = (user: number) => `${updated}:${user}`;

export function publishPreferences(preferences: ModePreferences, broadcast = true) {
    window.dispatchEvent(new CustomEvent(updated, { detail: preferences }));
    if (broadcast) {
        try {
            localStorage.setItem(storageKey(preferences.user_id), `${Date.now()}:${Math.random()}`);
        } catch {
            /* Storage is optional. */
        }
    }
}

export function useModePreferences() {
    const incoming = usePage<SharedData>().props.modePreferences as ModePreferences | null | undefined;
    const [preferences, setPreferences] = useState(incoming);
    useEffect(() => setPreferences(incoming), [incoming]);
    useEffect(() => {
        const listener = (event: Event) => {
            const next = (event as CustomEvent<ModePreferences>).detail;
            if (next.user_id === incoming?.user_id) setPreferences(next);
        };
        window.addEventListener(updated, listener);
        return () => window.removeEventListener(updated, listener);
    }, [incoming?.user_id]);
    return preferences;
}

export async function modeRequest(action: 'update' | 'choose' | 'tip' | 'show', data: Record<string, unknown> = {}) {
    const response = await fetch(route(`mode-preferences.${action}`), {
        method: action === 'show' ? 'GET' : action === 'update' ? 'PUT' : 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
        ...(action === 'show' ? {} : { body: JSON.stringify(data) }),
    });
    if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
        throw new Error('Your mode preference could not be saved. Please try again.');
    }
    const result = (await response.json()) as { preferences?: ModePreferences; show?: boolean };
    if (result.preferences) publishPreferences(result.preferences, action !== 'show');
    return result;
}

export function modeTarget(url: string, mode: MailMode) {
    const current = new URL(url, 'http://ats.local');
    const inMail = /^\/(incoming-mail|outgoing-mail|filed-mail|mail)(\/|$)/.test(current.pathname) || current.pathname === '/home';
    const target = inMail ? url : '/home?type=mail';
    return mailContextUrl(target, inMail ? url : target, mode, { view: null });
}

/** Only successful navigation clears this marker; failed saves retain protection. */
export function trackModeUnsavedWork() {
    const dirty = (event: Event) => {
        const target = event.target as HTMLElement;
        const form = target.closest('form');
        if (form && !form.matches('[role="search"], .home-search')) form.dataset.modeDirty = 'true';
    };
    const submitting = (event: Event) => {
        if (event.target instanceof HTMLFormElement) event.target.dataset.modeSubmitting = 'true';
    };
    document.addEventListener('input', dirty);
    document.addEventListener('change', dirty);
    document.addEventListener('submit', submitting, true);
    return () => {
        document.removeEventListener('input', dirty);
        document.removeEventListener('change', dirty);
        document.removeEventListener('submit', submitting, true);
    };
}

export function allowModeSwitch(): boolean {
    return (
        !document.querySelector('form[data-mode-dirty="true"]') || window.confirm('You have unsaved changes. Switch modes and discard these changes?')
    );
}

export function rememberModeSwitch(user: number | undefined, mode: MailMode) {
    if (user === undefined) return;
    try {
        sessionStorage.setItem(`ats:mode-switch:${user}`, mode);
    } catch {
        /* Optional feedback only. */
    }
}

export function consumeModeSwitch(user: number, mode: MailMode): boolean {
    try {
        const key = `ats:mode-switch:${user}`;
        const pending = sessionStorage.getItem(key);
        if (pending !== mode) return false;
        sessionStorage.removeItem(key);
        return true;
    } catch {
        return false;
    }
}
