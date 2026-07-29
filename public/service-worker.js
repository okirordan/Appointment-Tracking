/*
 * ATS service worker.
 *
 * Security model (this application handles confidential assignment and
 * correspondence data, so caching is allowlist-only):
 *
 *  - Navigations (HTML) are NEVER cached. They always go to the network;
 *    when the network is unreachable the branded offline page is shown.
 *    This guarantees no authenticated page, dashboard, report, or record
 *    can ever be served from Cache Storage — including after logout.
 *  - Only same-origin GET requests for immutable static interface assets
 *    (Vite build output, PWA icons, interface images, favicon) are cached.
 *    Evidence/attachment downloads and previews live under /evidence/ and
 *    /mail-attachments/, which are not in the allowlist and are therefore
 *    never cached.
 *  - Non-GET requests (login, logout, task actions, uploads, approvals…)
 *    are never intercepted, so CSRF, sessions, and redirects behave exactly
 *    as without a service worker, and they fail loudly while offline.
 *
 * Bump CACHE_VERSION whenever the precache list or caching logic changes;
 * activation deletes every cache belonging to older versions.
 */

const CACHE_VERSION = 'ats-v1';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;
const RUNTIME_CACHE_LIMIT = 80;

const OFFLINE_URL = '/offline.html';

// Small, stable set of assets required for the offline experience and the
// installed-app identity. Hashed Vite bundles are cached at runtime instead
// of being precached, so this list never goes stale between builds.
const PRECACHE_URLS = [
    OFFLINE_URL,
    '/pwa/offline.js',
    '/pwa/icons/icon-192x192.png',
    '/pwa/icons/icon-512x512.png',
    '/images/moes-crest.jpg',
    '/favicon.ico',
];

// Cache-first is only ever applied to paths on this allowlist. Everything
// else — Inertia JSON, reports, exports, downloads, previews, API-ish
// endpoints — is left to the browser and the normal HTTP layer.
const STATIC_ALLOWLIST = [
    '/build/',        // Vite output; filenames are content-hashed (immutable)
    '/pwa/',          // PWA icons and offline script
    '/images/',       // static interface images (crest); user uploads are
                      // stored outside /public/images and served via routes
    '/favicon.ico',
];

function isCacheableStaticAsset(url) {
    return STATIC_ALLOWLIST.some((prefix) =>
        prefix.endsWith('/') ? url.pathname.startsWith(prefix) : url.pathname === prefix,
    );
}

async function trimRuntimeCache() {
    const cache = await caches.open(RUNTIME_CACHE);
    const keys = await cache.keys();
    if (keys.length > RUNTIME_CACHE_LIMIT) {
        // Delete oldest entries first (Cache keys preserve insertion order).
        await Promise.all(keys.slice(0, keys.length - RUNTIME_CACHE_LIMIT).map((key) => cache.delete(key)));
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(STATIC_CACHE);
            // 'reload' bypasses the HTTP cache so a fresh worker always
            // precaches fresh copies.
            await cache.addAll(PRECACHE_URLS.map((url) => new Request(url, { cache: 'reload' })));
        })(),
    );
    // Deliberately no skipWaiting() here: the new worker waits until the
    // user accepts the in-app "Refresh now" prompt (see the message handler),
    // so open forms are never torn down unexpectedly.
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(
                names
                    .filter((name) => name.startsWith('ats-') && !name.startsWith(`${CACHE_VERSION}-`))
                    .map((name) => caches.delete(name)),
            );
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Never touch non-GET requests: form posts, uploads, approvals, logout…
    // must hit the server directly (and fail visibly when offline).
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Same-origin only; leave cross-origin requests entirely alone.
    if (url.origin !== self.location.origin) {
        return;
    }

    // Navigations: network only, with the branded offline fallback. The
    // response is intentionally never written to any cache.
    if (request.mode === 'navigate') {
        event.respondWith(
            (async () => {
                try {
                    return await fetch(request);
                } catch {
                    const cached = await caches.match(OFFLINE_URL);
                    return (
                        cached ||
                        new Response('You are currently offline.', {
                            status: 503,
                            headers: { 'Content-Type': 'text/plain' },
                        })
                    );
                }
            })(),
        );
        return;
    }

    // Static interface assets: cache first, then network (storing a copy).
    if (isCacheableStaticAsset(url)) {
        event.respondWith(
            (async () => {
                const cached = await caches.match(request);
                if (cached) {
                    return cached;
                }
                const response = await fetch(request);
                if (response.ok && (response.type === 'basic' || response.type === 'default')) {
                    const cache = await caches.open(RUNTIME_CACHE);
                    await cache.put(request, response.clone());
                    event.waitUntil(trimRuntimeCache());
                }
                return response;
            })(),
        );
    }

    // Any other GET (Inertia partial reloads, JSON, downloads, previews,
    // report exports…) is not intercepted: default browser behaviour.
});
