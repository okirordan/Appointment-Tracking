import InstallPrompt from '@/pwa/install-prompt';
import NetworkStatus from '@/pwa/network-status';
import TitleBar from '@/pwa/title-bar';
import UpdateNotification from '@/pwa/update-notification';

/**
 * Single mount point for all PWA UI, rendered at the application root
 * (app.tsx) so it covers every page — including login — regardless of
 * which layout the page uses.
 */
export default function PwaRoot() {
    return (
        <>
            <TitleBar />
            <NetworkStatus />
            <InstallPrompt />
            <UpdateNotification />
        </>
    );
}
