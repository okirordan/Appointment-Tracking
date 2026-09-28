export function csrfHeaders(): Record<string, string> {
    // Inertia can keep the original HTML shell after login rotates the session
    // token. Laravel refreshes this cookie on every web response.
    const xsrfCookie = document.cookie
        .split(';')
        .map((cookie) => cookie.trim())
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return xsrfCookie
        ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie) }
        : { 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' };
}
