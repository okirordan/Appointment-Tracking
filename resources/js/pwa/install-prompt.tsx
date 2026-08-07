import { Download, Share, X } from '@/components/icons';
import { useEffect, useState } from 'react';

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const DISMISS_KEY = 'ats-pwa-install-dismissed-at';
const DISMISS_DAYS = 14;

function dismissedRecently(): boolean {
    try {
        const at = Number(window.localStorage.getItem(DISMISS_KEY));
        return Number.isFinite(at) && at > 0 && Date.now() - at < DISMISS_DAYS * 24 * 60 * 60 * 1000;
    } catch {
        return false;
    }
}

function rememberDismissal(): void {
    try {
        window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
    } catch {
        // Storage unavailable (private mode) — the card simply reappears
        // next session, which is acceptable.
    }
}

function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        // iOS Safari exposes standalone mode through navigator.standalone.
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

function isIosSafari(): boolean {
    const ua = window.navigator.userAgent;
    const isIos = /iPad|iPhone|iPod/.test(ua) || (ua.includes('Macintosh') && navigator.maxTouchPoints > 1);
    // Exclude in-app / alternative browsers that cannot Add to Home Screen.
    return isIos && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);
}

/**
 * In-app install experience. Chromium browsers: captures beforeinstallprompt
 * and offers a dismissible "Install ATS" card. iOS Safari (which has no
 * install prompt API): shows one-time "Add to Home Screen" guidance instead.
 * Never shown while running as an installed app, and dismissal is remembered
 * for two weeks so users are not repeatedly interrupted.
 */
export default function InstallPrompt() {
    const [installEvent, setInstallEvent] = useState<BeforeInstallPromptEvent | null>(null);
    const [showIosGuide, setShowIosGuide] = useState(false);

    useEffect(() => {
        if (isStandalone() || dismissedRecently()) {
            return;
        }

        const handleBeforeInstall = (event: Event) => {
            event.preventDefault();
            setInstallEvent(event as BeforeInstallPromptEvent);
        };
        const handleInstalled = () => setInstallEvent(null);

        window.addEventListener('beforeinstallprompt', handleBeforeInstall);
        window.addEventListener('appinstalled', handleInstalled);

        if (isIosSafari()) {
            setShowIosGuide(true);
        }

        return () => {
            window.removeEventListener('beforeinstallprompt', handleBeforeInstall);
            window.removeEventListener('appinstalled', handleInstalled);
        };
    }, []);

    const dismiss = () => {
        rememberDismissal();
        setInstallEvent(null);
        setShowIosGuide(false);
    };

    const install = async () => {
        if (!installEvent) {
            return;
        }
        setInstallEvent(null);
        await installEvent.prompt();
        const choice = await installEvent.userChoice;
        if (choice.outcome === 'dismissed') {
            rememberDismissal();
        }
    };

    if (installEvent) {
        return (
            <section className="pwa-card" aria-label="Install ATS">
                <div className="pwa-card-icon">
                    <Download aria-hidden="true" />
                </div>
                <div className="pwa-card-body">
                    <strong>Install ATS</strong>
                    <p>Install the Assignment Tracking System on this device for faster access and an app-like experience.</p>
                    <div className="pwa-card-actions">
                        <button type="button" className="pwa-btn-primary" onClick={install}>
                            Install
                        </button>
                        <button type="button" className="pwa-btn-secondary" onClick={dismiss}>
                            Not Now
                        </button>
                    </div>
                </div>
                <button type="button" className="pwa-card-close" onClick={dismiss} aria-label="Dismiss install prompt">
                    <X aria-hidden="true" />
                </button>
            </section>
        );
    }

    if (showIosGuide) {
        return (
            <section className="pwa-card" aria-label="Install ATS on iOS">
                <div className="pwa-card-icon">
                    <Share aria-hidden="true" />
                </div>
                <div className="pwa-card-body">
                    <strong>Install ATS</strong>
                    <p>To install ATS on your iPhone or iPad, open the Share menu in Safari and select “Add to Home Screen”.</p>
                    <div className="pwa-card-actions">
                        <button type="button" className="pwa-btn-secondary" onClick={dismiss}>
                            Got it
                        </button>
                    </div>
                </div>
                <button type="button" className="pwa-card-close" onClick={dismiss} aria-label="Dismiss install guidance">
                    <X aria-hidden="true" />
                </button>
            </section>
        );
    }

    return null;
}
