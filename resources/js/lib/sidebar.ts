export const SIDEBAR_STORAGE_KEY = 'ats-sidebar-collapsed';

/**
 * Whether the desktop sidebar should render as an icon-only rail. Read
 * synchronously during the first render so the shell does not flash open
 * before the stored preference is applied.
 */
export function getSidebarCollapsed(): boolean {
    if (typeof window === 'undefined') return false;

    try {
        return window.localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

export function setSidebarCollapsed(collapsed: boolean): void {
    try {
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed ? '1' : '0');
    } catch {
        // A private browsing policy may block storage; the choice still
        // applies for the current page.
    }
}
