import { Head } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import FlashBridge from '@/components/ats/flash-bridge';
import Sidebar from '@/components/ats/sidebar';
import TempCredentialModal from '@/components/ats/temp-credential-modal';
import Topbar from '@/components/ats/topbar';

interface AppShellProps {
    title?: string;
    children: ReactNode;
}

export default function AppShell({ title, children }: AppShellProps) {
    const [sidebarOpen, setSidebarOpen] = useState(false);

    return (
        <div className="ats">
            {title !== undefined && <Head title={title} />}
            <FlashBridge />
            <TempCredentialModal />
            <div className="shell">
                <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
                <div className="main-col">
                    <Topbar onMenuClick={() => setSidebarOpen(true)} />
                    <main className="main">{children}</main>
                </div>
            </div>
        </div>
    );
}
