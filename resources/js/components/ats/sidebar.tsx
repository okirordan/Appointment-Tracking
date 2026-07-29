import LucideIcon from '@/components/ats/lucide-icon';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';

interface SidebarProps {
    open: boolean;
    onClose: () => void;
}

export default function Sidebar({ open, onClose }: SidebarProps) {
    const { auth, nav } = usePage<SharedData>().props;
    const user = auth.user!;

    return (
        <>
            {open && <div className="sidebar-backdrop" onClick={onClose} aria-hidden="true" />}
            <aside className={open ? 'sidebar open' : 'sidebar'}>
                <div className="brand">
                    <img src="/images/moes-crest.jpg" alt="MoES crest" />
                    <div>
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
                        >
                            <span className={`nav-icon tone-${item.tone}`}>
                                <LucideIcon name={item.icon} />
                            </span>
                            <span>{item.label}</span>
                        </Link>
                    ))}
                </nav>
                <div className="sidebar-foot">
                    <div className="who">
                        <div className="avatar">{user.initials}</div>
                        <div className="grow">
                            <div className="who-name">{user.full_name}</div>
                            <div className="who-role">{user.title ?? user.role_label}</div>
                        </div>
                    </div>
                    <button type="button" className="logout-btn" onClick={() => router.post(route('logout'))}>
                        <LogOut aria-hidden="true" />
                        Sign out
                    </button>
                </div>
            </aside>
        </>
    );
}
