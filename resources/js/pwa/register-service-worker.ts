/**
 * Service-worker registration and update plumbing.
 *
 * Registration only happens when all of these hold:
 *  - the browser supports service workers in a secure context
 *    (HTTPS or localhost),
 *  - the server enabled the PWA (config/pwa.php emits <meta name="ats-pwa">),
 *  - this is a production bundle — during `npm run dev` the worker is not
 *    only skipped but actively unregistered, so caching never interferes
 *    with HMR or debugging.
 *
 * Update flow: when a new worker reaches the "installed" state while an old
 * one is still controlling the page, an `ats:pwa-update` event is dispatched.
 * The UpdateNotification component shows a prompt; on "Refresh now" it calls
 * applyServiceWorkerUpdate(), which tells the waiting worker to skipWaiting
 * and reloads exactly once on controllerchange. Nothing reloads without the
 * user's approval, and the reload-guard prevents loops.
 */

export const PWA_UPDATE_EVENT = 'ats:pwa-update';

let refreshRequested = false;
let pendingUpdate: ServiceWorker | null = null;

/**
 * The waiting worker, if an update was announced before the notification
 * component mounted. Read once on mount so no update is ever lost to a
 * mount/announce race.
 */
export function getPendingUpdate(): ServiceWorker | null {
    return pendingUpdate;
}

function pwaEnabled(): boolean {
    return document.querySelector('meta[name="ats-pwa"]')?.getAttribute('content') === 'enabled';
}

function announceUpdate(worker: ServiceWorker): void {
    pendingUpdate = worker;
    window.dispatchEvent(new CustomEvent<ServiceWorker>(PWA_UPDATE_EVENT, { detail: worker }));
}

/**
 * Inertia's setup() runs after a dynamic page-chunk import, which can be
 * after the window load event has already fired — so "wait for load" alone
 * would silently never run. Run immediately in that case.
 */
function whenLoaded(callback: () => void): void {
    if (document.readyState === 'complete') {
        callback();
    } else {
        window.addEventListener('load', callback, { once: true });
    }
}

function watchRegistration(registration: ServiceWorkerRegistration): void {
    // A worker may already be waiting (e.g. the tab stayed open across a
    // deployment and a reload).
    if (registration.waiting && navigator.serviceWorker.controller) {
        announceUpdate(registration.waiting);
    }

    registration.addEventListener('updatefound', () => {
        const installing = registration.installing;
        if (!installing) {
            return;
        }
        installing.addEventListener('statechange', () => {
            if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                announceUpdate(installing);
            }
        });
    });
}

export function initServiceWorker(): void {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator) || !window.isSecureContext) {
        return;
    }

    if (!pwaEnabled() || import.meta.env.DEV) {
        // PWA disabled (or dev server): remove any worker left over from a
        // previous state so stale caches cannot linger.
        whenLoaded(() => {
            navigator.serviceWorker
                .getRegistrations()
                .then((registrations) => registrations.forEach((registration) => registration.unregister()))
                .catch(() => undefined);
        });
        return;
    }

    whenLoaded(async () => {
        try {
            const registration = await navigator.serviceWorker.register('/service-worker.js');
            watchRegistration(registration);

            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (refreshRequested) {
                    refreshRequested = false;
                    window.location.reload();
                }
            });
        } catch (error) {
            if (import.meta.env.DEV) {
                console.error('ATS service worker registration failed:', error);
            }
        }
    });
}

export function applyServiceWorkerUpdate(worker: ServiceWorker): void {
    refreshRequested = true;
    worker.postMessage({ type: 'SKIP_WAITING' });
}
