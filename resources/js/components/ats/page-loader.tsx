import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export function LoaderBar({ compact = false }: { compact?: boolean }) {
    return <span className={compact ? 'loader compact' : 'loader'} aria-hidden="true" />;
}

/**
 * One loader for every Inertia visit: pages, searches, filters, mutations,
 * and pagination. A short delay keeps cached/instant visits flicker-free.
 */
export default function PageLoader() {
    const [loading, setLoading] = useState(false);
    const showTimer = useRef<number | null>(null);

    useEffect(() => {
        const clearTimer = () => {
            if (showTimer.current !== null) {
                window.clearTimeout(showTimer.current);
                showTimer.current = null;
            }
        };

        const removeStart = router.on('start', () => {
            clearTimer();
            showTimer.current = window.setTimeout(() => setLoading(true), 160);
        });
        const removeFinish = router.on('finish', () => {
            clearTimer();
            setLoading(false);
        });

        return () => {
            clearTimer();
            removeStart();
            removeFinish();
        };
    }, []);

    if (!loading) {
        return null;
    }

    return (
        <div className="page-loading" role="status" aria-live="polite">
            <div className="page-loading-card">
                <LoaderBar />
                <span>Loading…</span>
            </div>
        </div>
    );
}
