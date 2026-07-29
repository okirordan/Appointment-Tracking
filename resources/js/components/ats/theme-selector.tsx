import { getThemePreference, setThemePreference, THEME_CHANGE_EVENT, type ThemePreference } from '@/lib/theme';
import { Monitor, Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

const options: Array<{
    value: ThemePreference;
    label: string;
    icon: typeof Sun;
}> = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

export default function ThemeSelector({ compact = false }: { compact?: boolean }) {
    const [theme, setTheme] = useState<ThemePreference>('system');

    useEffect(() => {
        setTheme(getThemePreference());
        const onChange = (event: Event) => setTheme((event as CustomEvent<ThemePreference>).detail);
        window.addEventListener(THEME_CHANGE_EVENT, onChange);

        return () => window.removeEventListener(THEME_CHANGE_EVENT, onChange);
    }, []);

    const choose = (preference: ThemePreference) => {
        setTheme(preference);
        setThemePreference(preference);
    };

    return (
        <div className={compact ? 'theme-selector compact' : 'theme-selector'} aria-label="Colour theme">
            {options.map((option) => {
                const Icon = option.icon;

                return (
                    <button
                        key={option.value}
                        type="button"
                        className={theme === option.value ? 'active' : ''}
                        aria-pressed={theme === option.value}
                        title={`${option.label} theme`}
                        onClick={() => choose(option.value)}
                    >
                        <Icon aria-hidden="true" />
                        <span>{option.label}</span>
                    </button>
                );
            })}
        </div>
    );
}
