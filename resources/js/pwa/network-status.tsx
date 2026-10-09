import { WifiOff } from '@/components/icons';
import { pushToast } from '@/lib/toast';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { appConnectionAvailable, currentAppConnectionAvailable } from './connectivity';

/**
 * Global connectivity awareness:
 *  - a persistent, non-blocking banner while the browser is offline;
 *  - a "Connection restored" toast when connectivity returns;
 *  - a guard that cancels non-GET Inertia visits while offline, so form
 *    submissions fail safely with clear feedback instead of hanging or
 *    silently discarding input. The visit is cancelled before anything is
 *    sent, so the form keeps its state and can be resubmitted (no duplicate
 *    submissions when the connection returns).
 */
export default function NetworkStatus() {
    const [online, setOnline] = useState(currentAppConnectionAvailable);

    useEffect(() => {
        const handleOffline = () => setOnline(currentAppConnectionAvailable());
        const handleOnline = () => {
            setOnline(true);
            if (!appConnectionAvailable(window.location.hostname, false)) {
                pushToast('success', 'Connection restored. You can retry any action that failed while offline.');
            }
        };

        window.addEventListener('offline', handleOffline);
        window.addEventListener('online', handleOnline);

        // Block writes while offline. GET navigations are left alone — the
        // service worker shows the branded offline page if they fail.
        const unsubscribe = router.on('before', (event) => {
            if (!currentAppConnectionAvailable() && event.detail.visit.method !== 'get') {
                event.preventDefault();
                pushToast('error', 'The app server is unavailable. Your changes have not yet been submitted.');
            }
        });

        return () => {
            window.removeEventListener('offline', handleOffline);
            window.removeEventListener('online', handleOnline);
            unsubscribe();
        };
    }, []);

    if (online) {
        return null;
    }

    return (
        <div className="pwa-offline-banner" role="status" aria-live="polite">
            <WifiOff aria-hidden="true" />
            <span>
                <strong>Connection unavailable.</strong> Assignments, reports, updates and approvals require a connection to the app server.
            </span>
        </div>
    );
}
