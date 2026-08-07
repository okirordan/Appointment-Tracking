import ThemeSelector from '@/components/ats/theme-selector';
import { Bell, BriefcaseBusiness, ChevronDown, LogOut, Menu, Search, Settings2, ShieldCheck, UsersRound } from '@/components/icons';
import type { NotificationItem, SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface TopbarProps {
    onMenuClick: () => void;
    sidebarCollapsed?: boolean;
}

export default function Topbar({ onMenuClick, sidebarCollapsed = false }: TopbarProps) {
    const { auth, notifications } = usePage<SharedData>().props;
    const user = auth.user!;

    const [q, setQ] = useState('');
    const [notifOpen, setNotifOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const rootRef = useRef<HTMLElement>(null);
    const highestNotificationRef = useRef<number | null>(null);

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

    useEffect(() => {
        const items = notifications?.items ?? [];
        const highest = items.reduce((value, item) => Math.max(value, item.id), 0);
        if (highestNotificationRef.current === null) {
            highestNotificationRef.current = highest;
            return;
        }
        const fresh = items.filter((item) => !item.is_read && item.id > (highestNotificationRef.current ?? 0));
        highestNotificationRef.current = Math.max(highestNotificationRef.current, highest);
        if (fresh.length === 0 || !('Notification' in window) || Notification.permission !== 'granted' || !('serviceWorker' in navigator)) return;

        navigator.serviceWorker.ready
            .then((registration) => {
                fresh.forEach((item) => {
                    void registration.showNotification(item.sensitive ? 'New assignment' : item.message, {
                        body: item.sensitive ? 'You have a new assignment. Open the system to view the details.' : item.detail || item.message,
                        tag: `notification-${item.id}`,
                        data: { url: item.action_url || route('home'), notificationId: item.id },
                        icon: '/pwa/icons/icon-192x192.png',
                        badge: '/pwa/icons/icon-96x96.png',
                    });
                });
            })
            .catch(() => undefined);
    }, [notifications]);

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
                    router.get(item.action_url || (item.task_id !== null ? route('tasks.show', item.task_id) : route('home')));
                },
            },
        );
    };

    const signOut = async () => {
        setUserMenuOpen(false);
        if ('serviceWorker' in navigator && 'PushManager' in window) {
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                if (subscription) {
                    await fetch(route('notifications.subscriptions.destroy'), {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ endpoint: subscription.endpoint }),
                    });
                    await subscription.unsubscribe();
                }
            } catch {
                // Logout must still proceed; server-side access checks prevent
                // a revoked account from receiving newly queued messages.
            }
        }
        router.post(route('logout'));
    };

    const switchWorkMode = (mode: 'administration' | 'officer') => {
        setUserMenuOpen(false);
        router.post(route('work-mode.update'), { mode });
    };

    return (
        <header className="topbar" ref={rootRef}>
            <button
                type="button"
                className="icon-btn menu-btn"
                onClick={onMenuClick}
                aria-label={sidebarCollapsed ? 'Expand navigation' : 'Collapse navigation'}
                aria-expanded={!sidebarCollapsed}
                title={sidebarCollapsed ? 'Expand navigation' : 'Collapse navigation'}
            >
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
                        {notifications !== null && notifications.unread_count > 0 && (
                            <span className="notification-count">{notifications.unread_count > 99 ? '99+' : notifications.unread_count}</span>
                        )}
                    </button>
                    {notifOpen && (
                        <div className="dropdown" style={{ width: 320 }}>
                            <div className="dropdown-hd">
                                <span>Notifications</span>
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
                                            {item.detail && <div className="notif-detail">{item.detail}</div>}
                                            <div className="notif-time">{item.time_label}</div>
                                        </div>
                                    </div>
                                ))
                            )}
                            <button type="button" className="notification-settings-link" onClick={() => router.get(route('notifications.settings'))}>
                                <Settings2 aria-hidden="true" /> Notification settings
                            </button>
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
                        <span className="role-btn-title">
                            {user.can_switch_work_mode
                                ? user.work_mode === 'administration'
                                    ? 'System Administration'
                                    : 'Officer Mode'
                                : (user.title ?? user.role_label)}
                        </span>
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
                                    {user.title && <div style={{ color: 'var(--muted)', fontSize: 11 }}>System access: {user.role_label}</div>}
                                </div>
                            </div>
                            {user.can_switch_work_mode && (
                                <div className="work-mode-menu" aria-label="Work mode">
                                    <button
                                        type="button"
                                        className={user.work_mode === 'administration' ? 'active' : ''}
                                        onClick={() => switchWorkMode('administration')}
                                    >
                                        <ShieldCheck aria-hidden="true" /> System Administration
                                    </button>
                                    <button
                                        type="button"
                                        className={user.work_mode === 'officer' ? 'active' : ''}
                                        onClick={() => switchWorkMode('officer')}
                                    >
                                        <BriefcaseBusiness aria-hidden="true" /> Officer Mode
                                    </button>
                                </div>
                            )}
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
                            <div className="dropdown-item" onClick={signOut}>
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
