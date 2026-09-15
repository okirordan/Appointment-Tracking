import { Monitor, Moon, Sun } from '@/components/icons';
import { getThemePreference, setThemePreference, THEME_CHANGE_EVENT, type ThemePreference } from '@/lib/theme';
import { useEffect, useState } from 'react';

const modes = {
    light: { next: 'dark', label: 'Dark', icon: Moon },
    dark: { next: 'system', label: 'System', icon: Monitor },
    system: { next: 'light', label: 'Light', icon: Sun },
} as const;

export default function ThemeSelector({ compact = false }: { compact?: boolean }) {
    const [theme, setTheme] = useState<ThemePreference>('system');
    useEffect(() => {
        setTheme(getThemePreference());
        const onChange = (event: Event) => setTheme((event as CustomEvent<ThemePreference>).detail);
        window.addEventListener(THEME_CHANGE_EVENT, onChange);
        return () => window.removeEventListener(THEME_CHANGE_EVENT, onChange);
    }, []);
    const next = modes[theme];
    const Icon = next.icon;
    return (
        <button
            type="button"
            className={`icon-btn appearance-toggle${compact ? 'compact' : ''}`}
            aria-label={`Switch to ${next.label} appearance`}
            title={`Switch to ${next.label} appearance (current: ${theme})`}
            onClick={() => {
                setThemePreference(next.next);
                setTheme(next.next);
            }}
        >
            <Icon aria-hidden="true" />
        </button>
    );
}
