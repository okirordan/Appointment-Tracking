import { pushToast } from '@/lib/toast';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    allowModeSwitch,
    consumeModeSwitch,
    modeRequest,
    modeTarget,
    rememberModeSwitch,
    storageKey,
    trackModeUnsavedWork,
    useModePreferences,
} from './mode-preferences-api';
import ModeSelectionDialog from './mode-selection-dialog';
import './mode.css';
import type { MailMode } from './navigation';

export default function ModeExperience() {
    const { url } = usePage();
    const preferences = useModePreferences();
    const [dismissed, setDismissed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [tip, setTip] = useState(false);
    const [switched, setSwitched] = useState(false);
    const prompt = preferences?.prompt && !dismissed;

    useEffect(() => {
        if (!preferences) return;
        const cleanup = trackModeUnsavedWork();
        const submitted = () => document.querySelectorAll<HTMLElement>('[data-mode-submitting]');
        const off = router.on('success', () =>
            submitted().forEach((form) => {
                delete form.dataset.modeDirty;
                delete form.dataset.modeSubmitting;
            }),
        );
        const offError = router.on('error', () => submitted().forEach((form) => delete form.dataset.modeSubmitting));
        return () => {
            cleanup();
            off();
            offError();
        };
    }, [preferences?.user_id]);

    useEffect(() => {
        setDismissed(false);
        setTip(false);
    }, [preferences?.user_id, preferences?.login_sequence]);

    useEffect(() => {
        if (!preferences) return;
        if (consumeModeSwitch(preferences.user_id, preferences.active_mode)) setSwitched(true);
        if (preferences.basic_tried || !preferences.tips_enabled || preferences.prompt) setTip(false);
    }, [preferences]);

    useEffect(() => {
        if (!switched) return;
        const timer = window.setTimeout(() => setSwitched(false), 12000);
        return () => window.clearTimeout(timer);
    }, [switched]);

    useEffect(() => {
        if (!preferences) return;
        const sync = (event: StorageEvent) => {
            if (event.key === storageKey(preferences.user_id)) void modeRequest('show').catch(() => undefined);
        };
        window.addEventListener('storage', sync);
        return () => window.removeEventListener('storage', sync);
    }, [preferences?.user_id]);

    useEffect(() => {
        if (!preferences?.tip_eligible || prompt) return;
        let cancelled = false;
        let timer: number;
        const schedule = () => {
            window.clearTimeout(timer);
            if (document.visibilityState === 'hidden') return;
            timer = window.setTimeout(async () => {
                if (document.querySelector('[role="dialog"], [role="alertdialog"]')) return;
                try {
                    const result = await modeRequest('tip');
                    if (!cancelled && result.show) setTip(true);
                } catch {
                    /* Promotional tips never interrupt work. */
                }
            }, 1000);
        };
        if (document.readyState === 'complete') schedule();
        else window.addEventListener('load', schedule, { once: true });
        document.addEventListener('visibilitychange', schedule);
        return () => {
            cancelled = true;
            window.clearTimeout(timer);
            window.removeEventListener('load', schedule);
            document.removeEventListener('visibilitychange', schedule);
        };
    }, [preferences?.tip_eligible, preferences?.user_id, preferences?.login_sequence, prompt, url]);

    if (!preferences) return null;

    const navigate = (mode: MailMode) => {
        rememberModeSwitch(preferences.user_id, mode);
        router.get(modeTarget(url, mode));
    };
    const choose = async (mode: MailMode, saveDefault: boolean) => {
        if (!allowModeSwitch()) return;
        setBusy(true);
        setError('');
        try {
            await modeRequest('choose', { mode, save_default: saveDefault });
            navigate(mode);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const later = async () => {
        if (!allowModeSwitch()) return;
        setDismissed(true);
        try {
            await modeRequest('choose', { mode: 'later' });
            router.get(modeTarget(url, 'full'));
        } catch {
            pushToast('error', 'Your choice could not be saved. You can keep working and try again later.');
        }
    };
    return (
        <>
            {prompt && (
                <ModeSelectionDialog
                    key={`${preferences.user_id}:${preferences.prompt}`}
                    preferences={preferences}
                    busy={busy}
                    error={error}
                    onChoose={choose}
                    onLater={later}
                />
            )}
            {tip && !prompt && (
                <aside className="mode-tip" aria-label="Basic Mode tip">
                    <p>Looking for a simpler way to manage your mail and correspondence? Try Basic Mode for a faster, task-focused experience.</p>
                    <div className="mode-tip-actions">
                        <button
                            className="btn btn-primary"
                            onClick={() => {
                                if (allowModeSwitch()) {
                                    setTip(false);
                                    navigate('basic');
                                }
                            }}
                        >
                            Try Basic Mode
                        </button>
                        <button className="btn btn-ghost" onClick={() => setTip(false)}>
                            Remind Me Later
                        </button>
                        <button
                            className="btn btn-ghost"
                            onClick={async () => {
                                try {
                                    await modeRequest('update', { tips_enabled: false });
                                    setTip(false);
                                } catch (failure) {
                                    pushToast('error', (failure as Error).message);
                                }
                            }}
                        >
                            Don't Show Again
                        </button>
                        <button className="close-btn" aria-label="Dismiss Basic Mode tip" onClick={() => setTip(false)}>
                            ×
                        </button>
                    </div>
                </aside>
            )}
            {switched && !prompt && (
                <div className="mode-switch-feedback" role="status">
                    <span>You're now using {preferences.active_mode === 'basic' ? 'Basic' : 'Full'} Mode.</span>
                    <button
                        type="button"
                        onClick={async () => {
                            try {
                                await modeRequest('update', { default_mode: preferences.active_mode });
                                setSwitched(false);
                                pushToast('success', 'Default mode saved.');
                            } catch (failure) {
                                pushToast('error', (failure as Error).message);
                            }
                        }}
                    >
                        Make this my default
                    </button>
                    <button type="button" aria-label="Dismiss mode confirmation" onClick={() => setSwitched(false)}>
                        ×
                    </button>
                </div>
            )}
        </>
    );
}
