import FlashBridge from '@/components/ats/flash-bridge';
import Sidebar from '@/components/ats/sidebar';
import TempCredentialModal from '@/components/ats/temp-credential-modal';
import Topbar from '@/components/ats/topbar';
import { getSidebarCollapsed, setSidebarCollapsed } from '@/lib/sidebar';
import { Head } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

interface AppShellProps {
    title?: string;
    children: ReactNode;
}

export default function AppShell({ title, children }: AppShellProps) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(getSidebarCollapsed);

    // Below the mobile breakpoint the sidebar is an off-canvas drawer, so the
    // same control opens it there instead of collapsing it to a rail.
    const toggleSidebar = () => {
        if (typeof window !== 'undefined' && window.matchMedia('(max-width: 900px)').matches) {
            setSidebarOpen((open) => !open);
            return;
        }

        setCollapsed((value) => {
            setSidebarCollapsed(!value);
            return !value;
        });
    };

    return (
        <div className="ats">
            {title !== undefined && <Head title={title} />}
            <FlashBridge />
            <TempCredentialModal />
            <div className={collapsed ? 'shell sidebar-collapsed' : 'shell'}>
                <Sidebar open={sidebarOpen} collapsed={collapsed} onClose={() => setSidebarOpen(false)} />
                <div className="main-col">
                    <Topbar onMenuClick={toggleSidebar} sidebarCollapsed={collapsed} />
                    <main className="main">{children}</main>
                </div>
            </div>
        </div>
    );
}
