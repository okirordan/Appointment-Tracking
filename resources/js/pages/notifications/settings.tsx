import AppShell from '@/components/ats/app-shell';
import FormErrorSummary from '@/components/ats/form-error-summary';
import { useForm } from '@inertiajs/react';
import { BellRing, CheckCircle2, LockKeyhole, MonitorSmartphone, Settings2, ShieldAlert } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Preferences {
    in_app_enabled: boolean;
    browser_enabled: boolean;
    email_enabled: boolean;
    new_assignments: boolean;
    assignment_views: boolean;
    deadline_reminders: boolean;
    completion_notifications: boolean;
    correspondence_updates: boolean;
    annotation_updates: boolean;
    office_correspondence: boolean;
}

interface Props {
    preferences: Preferences;
    permissionDeniedBefore: boolean;
    activeDeviceCount: number;
    vapidPublicKey: string;
    pushConfigured: boolean;
    emailConfigured: boolean;
    userEmail: string | null;
}

const categories: Array<{ key: keyof Preferences; title: string; detail: string }> = [
    { key: 'new_assignments', title: 'New assignments', detail: 'Personal, office and department assignments issued to you.' },
    { key: 'assignment_views', title: 'Assignment viewing alerts', detail: 'The first time each recipient opens an assignment you issued.' },
    { key: 'deadline_reminders', title: 'Deadline reminders', detail: 'Approaching deadlines and overdue assignment alerts.' },
    { key: 'completion_notifications', title: 'Completion updates', detail: 'Submissions, returns, reviews and completed assignments.' },
    { key: 'correspondence_updates', title: 'Correspondence updates', detail: 'Forwarding, routing, review and dispatch activity.' },
    { key: 'annotation_updates', title: 'Annotation updates', detail: 'New official annotations and instructions.' },
    { key: 'office_correspondence', title: 'Office correspondence', detail: 'Shared correspondence for offices and departments you belong to.' },
];

export default function NotificationSettings({ preferences, permissionDeniedBefore, activeDeviceCount, vapidPublicKey, pushConfigured, emailConfigured, userEmail }: Props) {
    const form = useForm({ ...preferences });
    const [browserState, setBrowserState] = useState<'idle' | 'working' | 'enabled' | 'blocked' | 'unsupported'>('idle');
    const [browserMessage, setBrowserMessage] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('notifications.preferences.update'), { preserveScroll: true });
    };

    const enableBrowser = async () => {
        if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
            setBrowserState('unsupported');
            setBrowserMessage('This browser does not support secure push notifications. In-app notifications will continue to work.');
            return;
        }

        setBrowserState('working');
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            setBrowserState('blocked');
            setBrowserMessage('Notifications are blocked. Use the site settings beside the address bar to allow them, then try again.');
            await fetch(route('notifications.permission-denied'), {
                method: 'POST',
                headers: jsonHeaders(),
                body: '{}',
            }).catch(() => undefined);
            return;
        }

        if (!pushConfigured || vapidPublicKey === '') {
            setBrowserState('blocked');
            setBrowserMessage('Browser permission is granted, but secure push delivery has not been configured by the administrator.');
            return;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const existing = await registration.pushManager.getSubscription();
            const subscription =
                existing ??
                (await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                }));
            const serialized = subscription.toJSON();
            const response = await fetch(route('notifications.subscriptions.store'), {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({
                    endpoint: subscription.endpoint,
                    public_key: serialized.keys?.p256dh,
                    auth_token: serialized.keys?.auth,
                    content_encoding: 'aes128gcm',
                    expires_at: serialized.expirationTime ? new Date(serialized.expirationTime).toISOString() : null,
                    device_label: navigator.userAgent,
                }),
            });
            if (!response.ok) throw new Error('The subscription could not be saved.');
            form.setData('browser_enabled', true);
            setBrowserState('enabled');
            setBrowserMessage('Browser notifications are enabled on this device. Save preferences to finish.');
        } catch {
            setBrowserState('blocked');
            setBrowserMessage('The browser granted permission, but this device could not be registered. Please try again.');
        }
    };

    const disableBrowser = async () => {
        setBrowserState('working');
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                await fetch(route('notifications.subscriptions.destroy'), {
                    method: 'DELETE',
                    headers: jsonHeaders(),
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });
                await subscription.unsubscribe();
            }
        } finally {
            form.setData('browser_enabled', false);
            setBrowserState('idle');
            setBrowserMessage('Browser notifications are disabled on this device.');
        }
    };

    return (
        <AppShell title="Notification settings">
            <div className="notification-settings-page">
                <header className="notification-settings-hero">
                    <div>
                        <span className="result-eyebrow">Alerts and delivery</span>
                        <h1>Notification settings</h1>
                        <p>Choose which assignment and correspondence events reach you, and where they are delivered.</p>
                    </div>
                    <div className="notification-device-summary">
                        <MonitorSmartphone aria-hidden="true" />
                        <strong>{activeDeviceCount}</strong>
                        <span>active browser {activeDeviceCount === 1 ? 'device' : 'devices'}</span>
                    </div>
                </header>

                <form onSubmit={submit} className="notification-settings-grid">
                    <FormErrorSummary errors={form.errors} />
                    <section className="card notification-channel-card">
                        <div className="notification-card-heading">
                            <span>
                                <BellRing aria-hidden="true" />
                            </span>
                            <div>
                                <h2>Delivery channels</h2>
                                <p>In-app alerts remain available even when browser notifications are off.</p>
                            </div>
                        </div>
                        <PreferenceToggle
                            title="In-app notifications"
                            detail="Show alerts in the notification centre while you are signed in."
                            checked={form.data.in_app_enabled}
                            onChange={(checked) => form.setData('in_app_enabled', checked)}
                        />
                        <PreferenceToggle
                            title="Email notifications"
                            detail={
                                !userEmail
                                    ? 'Ask an administrator to add your official email address to your profile.'
                                    : emailConfigured
                                      ? `Send alerts to ${userEmail}.`
                                      : 'Your email is recorded, but the administrator has not completed email delivery configuration.'
                            }
                            checked={form.data.email_enabled && Boolean(userEmail)}
                            disabled={!userEmail}
                            onChange={(checked) => form.setData('email_enabled', checked)}
                        />
                        <div className="browser-permission-panel">
                            <div>
                                <strong>Browser and PWA notifications</strong>
                                <p>
                                    Enable browser notifications to receive alerts about new assignments, deadlines and important correspondence
                                    updates.
                                </p>
                            </div>
                            {form.data.browser_enabled ? (
                                <button type="button" className="btn btn-ghost" disabled={browserState === 'working'} onClick={disableBrowser}>
                                    Disable on this device
                                </button>
                            ) : (
                                <button type="button" className="btn btn-primary" disabled={browserState === 'working'} onClick={enableBrowser}>
                                    {browserState === 'working' ? 'Enabling…' : 'Enable browser notifications'}
                                </button>
                            )}
                            {(browserMessage || permissionDeniedBefore) && (
                                <div className={`notification-permission-message ${browserState === 'enabled' ? 'success' : ''}`} role="status">
                                    {browserState === 'enabled' ? <CheckCircle2 aria-hidden="true" /> : <ShieldAlert aria-hidden="true" />}
                                    <span>
                                        {browserMessage ||
                                            'Permission was previously blocked. Allow notifications in your browser site settings before trying again.'}
                                    </span>
                                </div>
                            )}
                        </div>
                    </section>

                    <section className="card notification-category-card">
                        <div className="notification-card-heading">
                            <span>
                                <Settings2 aria-hidden="true" />
                            </span>
                            <div>
                                <h2>Notification categories</h2>
                                <p>Important security and account messages may still be shown.</p>
                            </div>
                        </div>
                        <div className="notification-category-list">
                            {categories.map((category) => (
                                <PreferenceToggle
                                    key={category.key}
                                    title={category.title}
                                    detail={category.detail}
                                    checked={form.data[category.key]}
                                    onChange={(checked) => form.setData(category.key, checked)}
                                />
                            ))}
                        </div>
                    </section>

                    <section className="notification-privacy-note">
                        <LockKeyhole aria-hidden="true" />
                        <div>
                            <strong>Confidentiality is protected</strong>
                            <p>
                                Sensitive push alerts contain no correspondence text, annotation, attachment name, personal information or restricted
                                reference. Sign in to view details.
                            </p>
                        </div>
                    </section>

                    <div className="notification-settings-actions">
                        <button type="submit" className="btn btn-primary" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save preferences'}
                        </button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}

function PreferenceToggle({
    title,
    detail,
    checked,
    onChange,
    disabled = false,
}: {
    title: string;
    detail: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
}) {
    return (
        <label className="notification-preference-row">
            <span>
                <strong>{title}</strong>
                <small>{detail}</small>
            </span>
            <input type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} />
            <span className="notification-switch" aria-hidden="true" />
        </label>
    );
}

function jsonHeaders(): HeadersInit {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        'X-Requested-With': 'XMLHttpRequest',
    };
}

function urlBase64ToUint8Array(value: string): Uint8Array {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    return Uint8Array.from([...raw].map((character) => character.charCodeAt(0)));
}
