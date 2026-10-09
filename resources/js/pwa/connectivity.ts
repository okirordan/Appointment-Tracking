/** Browser offline status can mean the internet is down while localhost remains reachable. */
export function appConnectionAvailable(hostname: string, browserOnline: boolean): boolean {
    return /^(localhost|127\.0\.0\.1|\[::1\]|::1)$/i.test(hostname) || browserOnline;
}

export function currentAppConnectionAvailable(): boolean {
    if (typeof window === 'undefined' || typeof navigator === 'undefined') return true;
    return appConnectionAvailable(window.location.hostname, navigator.onLine);
}
