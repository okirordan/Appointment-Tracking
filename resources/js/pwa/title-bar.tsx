import { Link, router } from '@inertiajs/react';
import { Home, WifiOff } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Minimal typing for the Window Controls Overlay API (Chromium desktop);
 * not yet part of the TypeScript DOM lib.
 */
interface WindowControlsOverlay extends EventTarget {
    readonly visible: boolean;
    getTitlebarAreaRect(): DOMRect;
}

declare global {
    interface Navigator {
        windowControlsOverlay?: WindowControlsOverlay;
    }
}

/**
 * Tracks whether the Window Controls Overlay is active. `geometrychange`
 * fires when the user toggles the overlay, enters/leaves fullscreen, or
 * resizes the window — so the bar appears and disappears correctly at
 * runtime, not just on load.
 */
function useWindowControlsOverlay(): boolean {
    const [visible, setVisible] = useState(() => (typeof navigator !== 'undefined' && navigator.windowControlsOverlay?.visible) ?? false);

    useEffect(() => {
        const wco = navigator.windowControlsOverlay;
        if (!wco) {
            return;
        }
        const handleGeometryChange = () => setVisible(wco.visible);
        // Sync in case visibility changed between first render and mount.
        handleGeometryChange();
        wco.addEventListener('geometrychange', handleGeometryChange);
        return () => wco.removeEventListener('geometrychange', handleGeometryChange);
    }, []);

    return visible;
}

interface PagePropsWithAuth {
    auth?: { user?: unknown };
}

/**
 * Whether a user is signed in. PwaRoot mounts outside Inertia's <App>, so
 * usePage() is unavailable here; read the initial page payload from the DOM
 * and follow changes through the router's navigate event (which also fires
 * for the initial visit).
 */
function useAuthenticated(): boolean {
    const [authenticated, setAuthenticated] = useState(() => {
        try {
            const page = JSON.parse(document.getElementById('app')?.dataset.page ?? '{}') as {
                props?: PagePropsWithAuth;
            };
            return Boolean(page.props?.auth?.user);
        } catch {
            return false;
        }
    });

    useEffect(() => router.on('navigate', (event) => setAuthenticated(Boolean((event.detail.page.props as PagePropsWithAuth).auth?.user))), []);

    return authenticated;
}

function useOnline(): boolean {
    const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine));

    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);
        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, []);

    return online;
}

/**
 * Custom title bar for the installed desktop app, rendered only while the
 * Window Controls Overlay is active. The whole strip is a draggable region
 * (app-region: drag) sized and positioned by the env(titlebar-area-*)
 * variables, so it automatically respects the native Windows
 * minimize/maximize/close cluster (and the left-side controls on macOS) —
 * the OS buttons are never recreated or covered. Interactive elements opt
 * out with app-region: no-drag.
 *
 * Everywhere else — browser tab, standalone without the overlay,
 * fullscreen, unsupported browsers — it renders nothing and the standard
 * title bar (painted with the manifest theme colour) is used instead.
 */
export default function TitleBar() {
    const overlayVisible = useWindowControlsOverlay();
    const online = useOnline();
    const authenticated = useAuthenticated();

    // Reserve space for the fixed bar (and shift the offline banner) only
    // while the overlay is active.
    useEffect(() => {
        document.documentElement.classList.toggle('wco-active', overlayVisible);
        return () => document.documentElement.classList.remove('wco-active');
    }, [overlayVisible]);

    if (!overlayVisible) {
        return null;
    }

    return (
        <header className="pwa-titlebar" aria-label="Application title bar">
            <img src="/pwa/icons/icon-72x72.png" alt="" aria-hidden="true" />
            <span className="pwa-titlebar-name">Assignment Tracking System</span>
            {!online && (
                <span className="pwa-titlebar-status" role="status">
                    <WifiOff aria-hidden="true" />
                    Offline
                </span>
            )}
            {authenticated && (
                <Link href="/home" className="pwa-titlebar-link" aria-label="Go to dashboard">
                    <Home aria-hidden="true" />
                </Link>
            )}
        </header>
    );
}
