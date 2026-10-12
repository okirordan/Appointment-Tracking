import FlashBridge from '@/components/ats/flash-bridge';
import ImpersonationBanner from '@/components/ats/impersonation-banner';
import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import BasicSiteHeader from './basic-site-header';
import './basic.css';
import ModeExperience from './mode-experience';

export default function BasicSettingsShell({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="mail-basic">
            <Head title={title} />
            <FlashBridge />
            <BasicSiteHeader />
            <ImpersonationBanner />
            <ModeExperience />
            <main>{children}</main>
            <footer className="global-footer">
                <span className="footer-flag" aria-hidden="true" />
                <span>
                    Developed by <strong>Department of Libraries, E-Learning and Information Technology</strong>
                </span>
            </footer>
        </div>
    );
}
