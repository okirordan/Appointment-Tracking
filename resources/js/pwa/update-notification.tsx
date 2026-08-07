import { RefreshCw } from '@/components/icons';
import { applyServiceWorkerUpdate, getPendingUpdate, PWA_UPDATE_EVENT } from '@/pwa/register-service-worker';
import { useEffect, useState } from 'react';

/**
 * Shown when a new service-worker version has installed and is waiting.
 * Nothing happens until the user chooses: "Refresh Now" activates the new
 * worker and reloads once; "Later" hides the prompt (the update activates
 * naturally the next time every ATS tab is closed). Open forms are never
 * torn down without consent.
 */
export default function UpdateNotification() {
    const [waitingWorker, setWaitingWorker] = useState<ServiceWorker | null>(null);

    useEffect(() => {
        // Pick up an update announced before this component mounted.
        setWaitingWorker((current) => current ?? getPendingUpdate());

        const listener = (event: Event) => setWaitingWorker((event as CustomEvent<ServiceWorker>).detail);
        window.addEventListener(PWA_UPDATE_EVENT, listener);
        return () => window.removeEventListener(PWA_UPDATE_EVENT, listener);
    }, []);

    if (!waitingWorker) {
        return null;
    }

    const refresh = () => {
        applyServiceWorkerUpdate(waitingWorker);
        setWaitingWorker(null);
    };

    return (
        <section className="pwa-card" role="status" aria-live="polite" aria-label="Application update available">
            <div className="pwa-card-icon">
                <RefreshCw aria-hidden="true" />
            </div>
            <div className="pwa-card-body">
                <strong>Update available</strong>
                <p>A new version of ATS is available. Refresh the application to load the latest improvements.</p>
                <div className="pwa-card-actions">
                    <button type="button" className="pwa-btn-primary" onClick={refresh}>
                        Refresh Now
                    </button>
                    <button type="button" className="pwa-btn-secondary" onClick={() => setWaitingWorker(null)}>
                        Later
                    </button>
                </div>
            </div>
        </section>
    );
}
