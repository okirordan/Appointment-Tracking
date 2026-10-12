import { useEffect, useState } from 'react';
import type { ModePreferences } from './mode-preferences-api';
import { modeRequest, useModePreferences } from './mode-preferences-api';
import './mode.css';

export default function ModePreferencesPanel() {
    const preferences = useModePreferences();
    const [defaultMode, setDefaultMode] = useState<ModePreferences['default_mode']>(preferences?.default_mode ?? 'full');
    const [tips, setTips] = useState(preferences?.tips_enabled ?? true);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    useEffect(() => {
        if (preferences) {
            setDefaultMode(preferences.default_mode);
            setTips(preferences.tips_enabled);
        }
    }, [preferences?.default_mode, preferences?.tips_enabled]);
    if (!preferences) return null;
    return (
        <section className="card mode-preferences-panel" id="mode-preferences">
            <h2>Mode Preferences</h2>
            <form
                data-mode-preferences
                onSubmit={async (event) => {
                    event.preventDefault();
                    setBusy(true);
                    setError('');
                    setMessage('');
                    const form = event.currentTarget;
                    try {
                        await modeRequest('update', { default_mode: defaultMode, tips_enabled: tips });
                        delete form.dataset.modeDirty;
                        setMessage('Mode preferences saved.');
                    } catch (failure) {
                        setError((failure as Error).message);
                    } finally {
                        delete form.dataset.modeSubmitting;
                        setBusy(false);
                    }
                }}
            >
                <label htmlFor="default-mail-mode">Default mode</label>
                <select
                    id="default-mail-mode"
                    value={defaultMode}
                    onChange={(event) => setDefaultMode(event.target.value as ModePreferences['default_mode'])}
                >
                    <option value="basic">Basic Mode</option>
                    <option value="full">Full Mode</option>
                    <option value="ask">Ask Me Each Time</option>
                </select>
                <label className="mode-checkbox">
                    <input type="checkbox" role="switch" checked={tips} onChange={(event) => setTips(event.target.checked)} />
                    Show occasional Basic Mode tips
                </label>
                <button className="btn btn-primary" disabled={busy}>
                    {busy ? 'Saving…' : 'Save mode preferences'}
                </button>
                {message && <p role="status">{message}</p>}
                {error && <p role="alert">{error}</p>}
            </form>
        </section>
    );
}
