import AppIcon from '@/components/ats/app-icon';
import { LogOut } from '@/components/icons';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';

interface SidebarProps {
    open: boolean;
    collapsed: boolean;
    onClose: () => void;
}

export default function Sidebar({ open, collapsed, onClose }: SidebarProps) {
    const { auth, nav } = usePage<SharedData>().props;
    const user = auth.user!;
    const roleLabel = user.title ?? user.role_label;

    return (
        <>
            {open && <div className="sidebar-backdrop" onClick={onClose} aria-hidden="true" />}
            <aside className={open ? 'sidebar open' : 'sidebar'}>
                <div className="brand">
                    <img src="/images/moes-crest.jpg" alt="MoES crest" />
                    <div className="brand-copy">
                        <div className="brand-t">ATS</div>
                        <div className="brand-s">Assignment Tracking System</div>
                    </div>
                </div>
                <nav className="nav" aria-label="Main navigation">
                    {nav.map((item) => (
                        <Link
                            key={item.key}
                            href={item.href}
                            className={item.active ? 'nav-item active' : 'nav-item'}
                            onClick={onClose}
                            aria-current={item.active ? 'page' : undefined}
                            // Collapsed to a rail the label is hidden, so the
                            // native tooltip is the only way to read it.
                            title={collapsed ? item.label : undefined}
                        >
                            <span className="nav-icon">
                                <AppIcon name={item.icon} />
                            </span>
                            <span className="nav-label">{item.label}</span>
                        </Link>
                    ))}
                </nav>
                <div className="sidebar-foot">
                    <div className="who" title={collapsed ? `${user.full_name} · ${roleLabel}` : undefined}>
                        <div className="avatar">{user.initials}</div>
                        <div className="grow">
                            <div className="who-name">{user.full_name}</div>
                            <div className="who-role">{roleLabel}</div>
                        </div>
                    </div>
                    <button
                        type="button"
                        className="logout-btn"
                        onClick={() => router.post(route('logout'))}
                        title={collapsed ? 'Sign out' : undefined}
                    >
                        <LogOut aria-hidden="true" />
                        <span className="nav-label">Sign out</span>
                    </button>
                </div>
            </aside>
        </>
    );
}
