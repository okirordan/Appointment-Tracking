import { WifiOff } from '@/components/icons';
import { pushToast } from '@/lib/toast';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
    const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine));

    useEffect(() => {
        const handleOffline = () => setOnline(false);
        const handleOnline = () => {
            setOnline(true);
            pushToast('success', 'Connection restored. You can retry any action that failed while offline.');
        };

        window.addEventListener('offline', handleOffline);
        window.addEventListener('online', handleOnline);

        // Block writes while offline. GET navigations are left alone — the
        // service worker shows the branded offline page if they fail.
        const unsubscribe = router.on('before', (event) => {
            if (!navigator.onLine && event.detail.visit.method !== 'get') {
                event.preventDefault();
                pushToast('error', 'This action requires an internet connection. Your changes have not yet been submitted.');
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
                <strong>You are offline.</strong> Assignments, reports, updates and approvals require an active connection.
            </span>
        </div>
    );
}
