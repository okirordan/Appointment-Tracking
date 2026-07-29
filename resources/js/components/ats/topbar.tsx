import ThemeSelector from '@/components/ats/theme-selector';
import type { NotificationItem, SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { Bell, ChevronDown, LogOut, Menu, Search, ShieldCheck, UsersRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface TopbarProps {
    onMenuClick: () => void;
}

export default function Topbar({ onMenuClick }: TopbarProps) {
    const { auth, notifications } = usePage<SharedData>().props;
    const user = auth.user!;

    const [q, setQ] = useState('');
    const [notifOpen, setNotifOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const rootRef = useRef<HTMLElement>(null);

    // Dropdowns are mutually exclusive and close on any outside interaction.
    useEffect(() => {
        const onOutside = (event: MouseEvent) => {
            if (rootRef.current && !rootRef.current.contains(event.target as Node)) {
                setNotifOpen(false);
                setUserMenuOpen(false);
            }
        };
        document.addEventListener('mousedown', onOutside);
        return () => document.removeEventListener('mousedown', onOutside);
    }, []);

    const submitSearch = () => {
        if (q.trim().length >= 2) {
            router.get(route('home'), { q: q.trim() });
        }
    };

    const openNotification = (item: NotificationItem) => {
        setNotifOpen(false);
        router.post(
            route('notifications.read', item.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (item.task_id !== null) {
                        router.get(route('tasks.show', item.task_id));
                    }
                },
            },
        );
    };

    return (
        <header className="topbar" ref={rootRef}>
            <button type="button" className="icon-btn menu-btn" onClick={onMenuClick} aria-label="Open navigation">
                <Menu aria-hidden="true" />
            </button>
            <div className="tb-search">
                <Search aria-hidden="true" />
                <input
                    type="search"
                    placeholder="Search tasks, officers, departments…"
                    aria-label="Global search"
                    value={q}
                    onChange={(event) => setQ(event.target.value)}
                    onKeyDown={(event) => event.key === 'Enter' && submitSearch()}
                />
            </div>
            <div className="tb-right">
                <div className="role-switch">
                    <button
                        type="button"
                        className="icon-btn"
                        aria-label="Notifications"
                        onClick={() => {
                            setNotifOpen((open) => !open);
                            setUserMenuOpen(false);
                        }}
                    >
                        <Bell aria-hidden="true" />
                        {notifications !== null && notifications.unread_count > 0 && <span className="dot" />}
                    </button>
                    {notifOpen && (
                        <div className="dropdown" style={{ width: 320 }}>
                            <div className="dropdown-hd">
                                Notifications
                                {notifications !== null && notifications.unread_count > 0 && (
                                    <Link
                                        href={route('notifications.read-all')}
                                        method="post"
                                        as="button"
                                        preserveScroll
                                        style={{
                                            fontSize: 11,
                                            textTransform: 'none',
                                            fontWeight: 500,
                                            color: 'var(--pri)',
                                            background: 'none',
                                            border: 'none',
                                            cursor: 'pointer',
                                        }}
                                    >
                                        Mark all read
                                    </Link>
                                )}
                            </div>
                            {notifications === null || notifications.items.length === 0 ? (
                                <div className="empty">No notifications</div>
                            ) : (
                                notifications.items.map((item) => (
                                    <div key={item.id} className={`notif-item${item.is_read ? '' : 'unread'}`} onClick={() => openNotification(item)}>
                                        <span className="notif-dot" style={{ opacity: item.is_read ? 0 : 1 }} />
                                        <div className="grow">
                                            <div className="notif-msg">{item.message}</div>
                                            <div className="notif-time">{item.time_label}</div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    )}
                </div>
                <div className="role-switch">
                    <button
                        type="button"
                        className="role-btn"
                        onClick={() => {
                            setUserMenuOpen((open) => !open);
                            setNotifOpen(false);
                        }}
                    >
                        <UsersRound aria-hidden="true" />
                        <span className="role-btn-title">{user.title ?? user.role_label}</span>
                        <ChevronDown aria-hidden="true" />
                    </button>
                    {userMenuOpen && (
                        <div className="dropdown">
                            <div className="dropdown-hd">Signed in</div>
                            <div className="dropdown-item" style={{ cursor: 'default' }}>
                                <div className="avatar" style={{ width: 28, height: 28, fontSize: 11 }}>
                                    {user.initials}
                                </div>
                                <div className="grow">
                                    <div style={{ fontWeight: 600 }}>{user.full_name}</div>
                                    <div style={{ color: 'var(--label)', fontSize: 11 }}>{user.title ?? user.role_label}</div>
                                    {user.title && <div style={{ color: 'var(--muted)', fontSize: 10 }}>System access: {user.role_label}</div>}
                                </div>
                            </div>
                            <div
                                className="dropdown-item"
                                onClick={() => {
                                    setUserMenuOpen(false);
                                    router.get(route('password.change'));
                                }}
                            >
                                Change password
                            </div>
                            <div
                                className="dropdown-item"
                                onClick={() => {
                                    setUserMenuOpen(false);
                                    router.get(route('security.show'));
                                }}
                            >
                                <ShieldCheck aria-hidden="true" style={{ width: 15, height: 15, color: 'var(--label)' }} />
                                Security &amp; two-factor
                                {user.two_factor_enabled && <span className="menu-status-chip">On</span>}
                            </div>
                            <div className="dropdown-theme">
                                <span>Appearance</span>
                                <ThemeSelector compact />
                            </div>
                            <div className="dropdown-item" onClick={() => router.post(route('logout'))}>
                                <LogOut aria-hidden="true" style={{ width: 15, height: 15, color: 'var(--label)' }} />
                                Sign out
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}
