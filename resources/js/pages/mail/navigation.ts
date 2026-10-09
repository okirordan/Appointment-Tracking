export type MailMode = 'basic' | 'full';
const contextKeys = [
    'mode',
    'view',
    'section',
    'q',
    'status',
    'priority',
    'department_id',
    'assigned_to_user_id',
    'financial_year',
    'date_from',
    'date_to',
    'page',
    'recipient',
    'category',
];

/** Carry only mail UI context, never record fields or an arbitrary return URL. */
export function mailContextUrl(target: string, current: string, mode: MailMode, changes: Record<string, string | number | null> = {}) {
    const result = new URL(target, 'http://ats.local');
    const source = new URL(current, 'http://ats.local');
    for (const key of contextKeys) {
        const value = source.searchParams.get(key);
        if (value !== null) result.searchParams.set(key, value);
    }
    result.searchParams.set('mode', mode);
    for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === '') result.searchParams.delete(key);
        else result.searchParams.set(key, String(value));
    }
    return result.pathname + result.search;
}
