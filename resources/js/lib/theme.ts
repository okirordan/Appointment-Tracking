export type ThemePreference = 'light' | 'dark' | 'system';

export const THEME_STORAGE_KEY = 'ats-theme';
export const THEME_CHANGE_EVENT = 'ats-theme-change';

const isThemePreference = (value: string | null): value is ThemePreference => value === 'light' || value === 'dark' || value === 'system';

export function getThemePreference(): ThemePreference {
    if (typeof window === 'undefined') return 'system';

    try {
        const saved = window.localStorage.getItem(THEME_STORAGE_KEY);
        return isThemePreference(saved) ? saved : 'system';
    } catch {
        return 'system';
    }
}

export function resolvedTheme(preference: ThemePreference): 'light' | 'dark' {
    if (preference !== 'system') return preference;
    if (typeof window === 'undefined') return 'light';

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function applyTheme(preference: ThemePreference): void {
    if (typeof document === 'undefined') return;

    const resolved = resolvedTheme(preference);
    document.documentElement.dataset.theme = preference;
    document.documentElement.style.colorScheme = resolved;

    const themeMeta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');
    if (themeMeta) themeMeta.content = resolved === 'dark' ? '#0f172a' : '#155dfc';
}

export function initializeTheme(): ThemePreference {
    const preference = getThemePreference();
    applyTheme(preference);

    if (typeof window !== 'undefined') {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (getThemePreference() === 'system') applyTheme('system');
        });
    }

    return preference;
}

export function setThemePreference(preference: ThemePreference): void {
    try {
        window.localStorage.setItem(THEME_STORAGE_KEY, preference);
    } catch {
        // A private browsing policy may block storage; the active page can
        // still use the selected theme.
    }

    applyTheme(preference);
    window.dispatchEvent(new CustomEvent<ThemePreference>(THEME_CHANGE_EVENT, { detail: preference }));
}
