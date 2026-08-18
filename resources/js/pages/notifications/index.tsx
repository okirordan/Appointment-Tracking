import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import Pagination from '@/components/ats/pagination';
import { Bell, BellRing, Check, FileText, MessageSquareText, Settings2 } from '@/components/icons';
import { pushToast } from '@/lib/toast';
import type { NotificationItem, PaginatedData } from '@/types';
import { Link, router } from '@inertiajs/react';

export default function NotificationsIndex({ notificationsPage }: { notificationsPage: PaginatedData<NotificationItem> }) {
    const open = (item: NotificationItem) => {
        router.post(
            route('notifications.read', item.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => router.get(item.action_url || (item.task_id ? route('tasks.show', item.task_id) : route('home'))),
                onError: () => pushToast('error', 'Unable to open this notification. Please try again.'),
            },
        );
    };

    return (
        <AppShell title="Notifications">
            <div className="page-hd notification-page-heading">
                <div>
                    <span className="result-eyebrow">Activity centre</span>
                    <h1>Notifications</h1>
                    <p className="page-sub">{notificationsPage.meta.total} notification(s)</p>
                </div>
                <div className="notification-page-actions">
                    <Link className="btn btn-ghost" href={route('notifications.read-all')} method="post" as="button" preserveScroll>
                        <Check aria-hidden="true" />
                        Mark all as read
                    </Link>
                    <Link className="btn btn-ghost" href={route('notifications.settings')}>
                        <Settings2 aria-hidden="true" />
                        Settings
                    </Link>
                </div>
            </div>
            <section className="card notification-page-list">
                {notificationsPage.data.length === 0 ? (
                    <EmptyState>
                        <Bell aria-hidden="true" />
                        <strong>You’re all caught up</strong>
                        <span>New assignment and correspondence updates will appear here.</span>
                    </EmptyState>
                ) : (
                    notificationsPage.data.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            className={`notification-page-item${item.is_read ? '' : 'unread'}`}
                            onClick={() => open(item)}
                        >
                            <span className="notification-type-icon">
                                {item.type === 'annotation' ? <MessageSquareText /> : item.mail_id ? <FileText /> : <BellRing />}
                            </span>
                            <span className="notification-page-copy">
                                <strong>{item.sensitive ? 'Protected correspondence update' : item.message}</strong>
                                {item.detail && <span>{item.sensitive ? 'Open the system to view this protected update.' : item.detail}</span>}
                                <small>{item.time_label}</small>
                            </span>
                            {!item.is_read && <span className="notification-unread-label">Unread</span>}
                        </button>
                    ))
                )}
            </section>
            <Pagination meta={notificationsPage.meta} />
        </AppShell>
    );
}
