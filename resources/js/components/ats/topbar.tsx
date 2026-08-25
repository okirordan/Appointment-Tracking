import ThemeSelector from '@/components/ats/theme-selector';
import {
    Bell,
    BellRing,
    BriefcaseBusiness,
    Check,
    ChevronDown,
    FileText,
    LockKeyhole,
    LogOut,
    Menu,
    MessageSquareText,
    Search,
    Settings2,
    ShieldCheck,
} from '@/components/icons';
import { pushToast } from '@/lib/toast';
import type { NotificationItem, SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface TopbarProps {
    onMenuClick: () => void;
    sidebarCollapsed?: boolean;
}

function notificationContext(item: NotificationItem): string {
    if (item.type === 'annotation') return 'Annotation';
    if (item.mail_id !== null) return 'Correspondence';
    if (item.task_id !== null) return 'Assignment';

    return 'System update';
}

export default function Topbar({ onMenuClick, sidebarCollapsed = false }: TopbarProps) {
    const { auth, notifications } = usePage<SharedData>().props;
    const user = auth.user!;

    const [q, setQ] = useState('');
    const [notifOpen, setNotifOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [notificationLoading, setNotificationLoading] = useState(false);
    const [notificationError, setNotificationError] = useState(false);
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

    useEffect(() => {
        const refresh = () => {
            if (document.hidden) return;
            setNotificationLoading(true);
            router.reload({
                only: ['notifications'],
                onSuccess: () => setNotificationError(false),
                onError: () => setNotificationError(true),
                onFinish: () => setNotificationLoading(false),
            });
        };
        const timer = window.setInterval(refresh, 30_000);
        window.addEventListener('focus', refresh);
        return () => {
            window.clearInterval(timer);
            window.removeEventListener('focus', refresh);
        };
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
                    router.get(item.action_url || (item.task_id !== null ? route('tasks.show', item.task_id) : route('home')));
                },
                onError: () => pushToast('error', 'Unable to open this notification. Please try again.'),
            },
        );
    };

    const markNotificationRead = (event: React.MouseEvent, item: NotificationItem) => {
        event.stopPropagation();
        router.post(
            route('notifications.read', item.id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['notifications'],
                onError: () => pushToast('error', 'Unable to mark this notification as read.'),
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
                        <div className="dropdown notification-dropdown" role="dialog" aria-label="Notifications">
                            <div className="notification-dropdown-header">
                                <div>
                                    <span className="notification-dropdown-kicker">Activity centre</span>
                                    <div className="notification-dropdown-title">
                                        <strong>Notifications</strong>
                                        {notifications !== null && notifications.unread_count > 0 && <span>{notifications.unread_count} unread</span>}
                                    </div>
                                    <small>Assignment and correspondence updates</small>
                                </div>
                                {notifications !== null && notifications.unread_count > 0 && (
                                    <Link
                                        href={route('notifications.read-all')}
                                        method="post"
                                        as="button"
                                        preserveScroll
                                        className="notification-mark-all"
                                    >
                                        Mark all read
                                    </Link>
                                )}
                            </div>
                            {notificationLoading && notifications === null ? (
                                <div className="notification-dropdown-state">Loading notifications…</div>
                            ) : notificationError ? (
                                <div className="notification-dropdown-state is-error">
                                    Notifications could not be refreshed. They will retry automatically.
                                </div>
                            ) : notifications === null || notifications.items.length === 0 ? (
                                <div className="notification-dropdown-empty">
                                    <Bell aria-hidden="true" />
                                    <strong>You’re all caught up</strong>
                                    <span>No new notifications.</span>
                                </div>
                            ) : (
                                <div className="notification-scroll">
                                    {notifications.items.map((item) => (
                                        <article key={item.id} className={`notif-item${item.is_read ? '' : 'unread'}`}>
                                            <button type="button" className="notif-open-button" onClick={() => openNotification(item)}>
                                                <span className="notif-icon" aria-hidden="true">
                                                    {item.type === 'annotation' ? <MessageSquareText /> : item.mail_id ? <FileText /> : <BellRing />}
                                                </span>
                                                <span className="notif-copy">
                                                    <span className="notif-message-heading">
                                                        <strong className="notif-msg">
                                                            {item.sensitive ? 'Protected correspondence update' : item.message}
                                                        </strong>
                                                        {!item.is_read && <span className="notif-new-label">New</span>}
                                                    </span>
                                                    {item.detail && (
                                                        <span className="notif-detail">
                                                            {item.sensitive ? 'Open the system to view this protected update.' : item.detail}
                                                        </span>
                                                    )}
                                                    <span className="notif-meta">
                                                        <span>{notificationContext(item)}</span>
                                                        <time>{item.time_label}</time>
                                                    </span>
                                                </span>
                                            </button>
                                            {!item.is_read && (
                                                <button
                                                    type="button"
                                                    className="notif-read-button"
                                                    onClick={(event) => markNotificationRead(event, item)}
                                                    aria-label="Mark as read"
                                                    title="Mark as read"
                                                >
                                                    <Check />
                                                </button>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            )}
                            <div className="notification-dropdown-footer">
                                <button type="button" onClick={() => router.get(route('notifications.index'))}>
                                    View all notifications
                                </button>
                                <button type="button" onClick={() => router.get(route('notifications.settings'))}>
                                    <Settings2 aria-hidden="true" /> Settings
                                </button>
                            </div>
                        </div>
                    )}
                </div>
                <div className="role-switch">
                    <button
                        type="button"
                        className="role-btn profile-trigger"
                        aria-haspopup="menu"
                        aria-expanded={userMenuOpen}
                        onClick={() => {
                            setUserMenuOpen((open) => !open);
                            setNotifOpen(false);
                        }}
                    >
                        <span className="profile-trigger-avatar" aria-hidden="true">
                            {user.initials}
                        </span>
                        <span className="profile-trigger-copy">
                            <strong>{user.full_name}</strong>
                            <small>{user.title ?? user.role_label}</small>
                        </span>
                        <ChevronDown aria-hidden="true" />
                    </button>
                    {userMenuOpen && (
                        <div className="dropdown profile-dropdown" role="menu">
                            <div className="profile-dropdown-summary">
                                <div className="avatar profile-dropdown-avatar" aria-hidden="true">
                                    {user.initials}
                                </div>
                                <div className="profile-dropdown-identity">
                                    <strong>{user.full_name}</strong>
                                    <span>{user.title ?? user.role_label}</span>
                                </div>
                            </div>
                            {user.can_switch_work_mode && (
                                <div className="profile-dropdown-section">
                                    <span className="profile-section-label">Work mode</span>
                                    <div className="work-mode-menu profile-work-mode" aria-label="Work mode">
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
                                </div>
                            )}
                            <div className="profile-dropdown-section profile-account-actions">
                                <span className="profile-section-label">Account</span>
                                <button
                                    type="button"
                                    className="profile-menu-item"
                                    onClick={() => {
                                        setUserMenuOpen(false);
                                        router.get(route('password.change'));
                                    }}
                                >
                                    <LockKeyhole aria-hidden="true" />
                                    <span>
                                        <strong>Change password</strong>
                                        <small>Update your sign-in password</small>
                                    </span>
                                </button>
                                <button
                                    type="button"
                                    className="profile-menu-item"
                                    onClick={() => {
                                        setUserMenuOpen(false);
                                        router.get(route('security.show'));
                                    }}
                                >
                                    <ShieldCheck aria-hidden="true" />
                                    <span>
                                        <strong>Security &amp; two-factor</strong>
                                        <small>{user.two_factor_enabled ? 'Two-factor authentication is on' : 'Manage account security'}</small>
                                    </span>
                                    {user.two_factor_enabled && <span className="menu-status-chip">On</span>}
                                </button>
                            </div>
                            <div className="dropdown-theme profile-theme">
                                <span>Appearance</span>
                                <ThemeSelector compact />
                            </div>
                            <button type="button" className="profile-signout" onClick={signOut}>
                                <LogOut aria-hidden="true" />
                                Sign out
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}
