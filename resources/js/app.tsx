import '../css/app.css';

import GlobalFooter from '@/components/ats/global-footer';
import PageLoader from '@/components/ats/page-loader';
import { ConfirmProvider } from '@/hooks/use-confirm';
import { pushToast } from '@/lib/toast';
import PwaRoot from '@/pwa/pwa-root';
import { initServiceWorker } from '@/pwa/register-service-worker';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'ATS';

// Surface unexpected request failures (network drop, unhandled server
// error) through the toast system instead of a browser dialog or a silent
// failure. HTTP errors with debug off render as friendly Inertia error
// pages server-side, so they navigate normally. Server flash messages are
// bridged to toasts by <FlashBridge> inside the app.
function registerGlobalHandlers(): void {
    router.on('exception', (event) => {
        event.preventDefault();
        pushToast('error', 'A network or server error occurred. Please try again.');
    });
}

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        registerGlobalHandlers();
        initServiceWorker();

        const root = createRoot(el);

        root.render(
            <ConfirmProvider>
                <div className="application-frame">
                    <App {...props} />
                    <GlobalFooter />
                </div>
                <PageLoader />
                <PwaRoot />
            </ConfirmProvider>,
        );
    },
    // The default progress bar (a thin brand-blue line) is invisible in the
    // installed PWA, where the title-bar strip is the same blue. PageLoader
    // renders one themed loader for pages, searches, and pagination.
    progress: false,
});
