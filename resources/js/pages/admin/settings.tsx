import AppShell from '@/components/ats/app-shell';
import FormErrorSummary from '@/components/ats/form-error-summary';
import { AlertCircle, Check, MailCheck, Send, Server, Settings2, ShieldCheck } from '@/components/icons';
import { useConfirm } from '@/hooks/use-confirm';
import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

interface Props {
    branding: {
        ministry_full_name: string;
        ministry_short_name: string;
        system_title: string;
    };
    emailConfiguration: {
        enabled: boolean;
        host: string | null;
        port: number;
        encryption: 'tls' | 'ssl' | 'none';
        username: string | null;
        from_address: string | null;
        from_name: string | null;
        password_configured: boolean;
    };
    purgeEnabled: boolean;
    mailFeatures: Array<{ key: string; label: string; enabled: boolean }>;
}

export default function Settings({ branding, emailConfiguration, purgeEnabled, mailFeatures }: Props) {
    const brandingForm = useForm(branding);
    const emailForm = useForm({
        enabled: emailConfiguration.enabled,
        host: emailConfiguration.host ?? '',
        port: emailConfiguration.port,
        encryption: emailConfiguration.encryption,
        username: emailConfiguration.username ?? '',
        password: '',
        clear_password: false as boolean,
        from_address: emailConfiguration.from_address ?? '',
        from_name: emailConfiguration.from_name ?? '',
    });
    const testForm = useForm({ recipient: '' });
    const mailFeatureForm = useForm({
        features: Object.fromEntries(mailFeatures.map((feature) => [feature.key, feature.enabled])) as Record<string, boolean>,
    });
    const confirm = useConfirm();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        brandingForm.post(route('admin.settings.update'));
    };

    const saveEmail = (event: FormEvent) => {
        event.preventDefault();
        emailForm.put(route('admin.settings.email.update'), { preserveScroll: true });
    };

    const sendTest = (event: FormEvent) => {
        event.preventDefault();
        testForm.post(route('admin.settings.email.test'), { preserveScroll: true });
    };

    const saveMailFeatures = (event: FormEvent) => {
        event.preventDefault();
        mailFeatureForm.put(route('admin.settings.mail-features.update'), { preserveScroll: true });
    };

    const requestPurge = async () => {
        const ok = await confirm({
            title: 'Purge demo data?',
            message:
                'This permanently deletes all tasks, history, evidence, and notifications. You will be asked to re-enter your password, and the action is written to the audit log.',
            confirmLabel: 'Purge everything',
            variant: 'danger',
        });
        if (ok) {
            router.post(route('admin.settings.purge'));
        }
    };

    return (
        <AppShell title="Settings">
            <div className="page-hd">
                <div>
                    <h1>Settings</h1>
                </div>
            </div>
            <div className="grid2">
                <form className="card mail-feature-settings-card" onSubmit={saveMailFeatures}>
                    <div className="card-hd">
                        <h3>
                            <Settings2 aria-hidden="true" /> Correspondence form features
                        </h3>
                    </div>
                    <FormErrorSummary errors={mailFeatureForm.errors} />
                    <div className="mail-feature-settings-list">
                        {mailFeatures.map((feature) => (
                            <label key={feature.key} className="mail-feature-setting">
                                <span>{feature.label}</span>
                                <input
                                    type="checkbox"
                                    checked={mailFeatureForm.data.features[feature.key] ?? false}
                                    onChange={(event) =>
                                        mailFeatureForm.setData('features', {
                                            ...mailFeatureForm.data.features,
                                            [feature.key]: event.target.checked,
                                        })
                                    }
                                />
                            </label>
                        ))}
                    </div>
                    <button type="submit" className="btn btn-primary" disabled={mailFeatureForm.processing}>
                        <Check aria-hidden="true" /> {mailFeatureForm.processing ? 'Saving…' : 'Save correspondence settings'}
                    </button>
                </form>
                <form className="card" onSubmit={submit}>
                    <div className="card-hd">
                        <h3>Ministry Branding</h3>
                    </div>
                    <FormErrorSummary errors={brandingForm.errors} />
                    <div className="field">
                        <label htmlFor="st-full">Ministry Full Name</label>
                        <input
                            id="st-full"
                            type="text"
                            value={brandingForm.data.ministry_full_name}
                            onChange={(event) => brandingForm.setData('ministry_full_name', event.target.value)}
                        />
                        {brandingForm.errors.ministry_full_name && <div className="field-error">{brandingForm.errors.ministry_full_name}</div>}
                    </div>
                    <div className="field" style={{ marginTop: 12 }}>
                        <label htmlFor="st-short">Ministry Short Name</label>
                        <input
                            id="st-short"
                            type="text"
                            value={brandingForm.data.ministry_short_name}
                            onChange={(event) => brandingForm.setData('ministry_short_name', event.target.value)}
                        />
                        {brandingForm.errors.ministry_short_name && <div className="field-error">{brandingForm.errors.ministry_short_name}</div>}
                    </div>
                    <div className="field" style={{ marginTop: 12 }}>
                        <label htmlFor="st-title">System Title</label>
                        <input
                            id="st-title"
                            type="text"
                            value={brandingForm.data.system_title}
                            onChange={(event) => brandingForm.setData('system_title', event.target.value)}
                        />
                        {brandingForm.errors.system_title && <div className="field-error">{brandingForm.errors.system_title}</div>}
                    </div>
                    <button type="submit" className="btn btn-primary" style={{ marginTop: 16 }} disabled={brandingForm.processing}>
                        <Check aria-hidden="true" />
                        Save Branding
                    </button>
                </form>
                <form className="card email-configuration-card" onSubmit={saveEmail}>
                    <div className="email-configuration-heading">
                        <span>
                            <Server aria-hidden="true" />
                        </span>
                        <div>
                            <h3>Email notifications</h3>
                            <p>Configure the ministry SMTP server used for assignments, unassignments and correspondence alerts.</p>
                        </div>
                        <label className="email-enabled-toggle">
                            <input
                                type="checkbox"
                                checked={emailForm.data.enabled}
                                onChange={(event) => emailForm.setData('enabled', event.target.checked)}
                            />
                            <span>{emailForm.data.enabled ? 'Enabled' : 'Disabled'}</span>
                        </label>
                    </div>
                    <FormErrorSummary errors={emailForm.errors} />
                    <div className="email-settings-grid">
                        <div className="field">
                            <label htmlFor="mail-host">SMTP host</label>
                            <input
                                id="mail-host"
                                value={emailForm.data.host}
                                onChange={(event) => emailForm.setData('host', event.target.value)}
                                placeholder="smtp.office365.com"
                            />
                        </div>
                        <div className="field">
                            <label htmlFor="mail-port">Port</label>
                            <input
                                id="mail-port"
                                type="number"
                                min="1"
                                max="65535"
                                value={emailForm.data.port}
                                onChange={(event) => emailForm.setData('port', Number(event.target.value))}
                            />
                        </div>
                        <div className="field">
                            <label htmlFor="mail-encryption">Encryption</label>
                            <select
                                id="mail-encryption"
                                value={emailForm.data.encryption}
                                onChange={(event) => emailForm.setData('encryption', event.target.value as 'tls' | 'ssl' | 'none')}
                            >
                                <option value="tls">TLS / STARTTLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">None (trusted internal relay only)</option>
                            </select>
                        </div>
                        <div className="field">
                            <label htmlFor="mail-username">Username</label>
                            <input
                                id="mail-username"
                                autoComplete="username"
                                value={emailForm.data.username}
                                onChange={(event) => emailForm.setData('username', event.target.value)}
                                placeholder="notifications@education.go.ug"
                            />
                        </div>
                        <div className="field">
                            <label htmlFor="mail-password">Password</label>
                            <input
                                id="mail-password"
                                type="password"
                                autoComplete="new-password"
                                value={emailForm.data.password}
                                onChange={(event) => {
                                    const password = event.target.value;
                                    emailForm.setData((current) => ({
                                        ...current,
                                        password,
                                        clear_password: password.length > 0 ? false : current.clear_password,
                                    }));
                                }}
                                placeholder={emailConfiguration.password_configured ? 'Stored securely — leave blank to keep' : 'SMTP password'}
                            />
                            <small>
                                {emailConfiguration.password_configured
                                    ? 'A password is stored encrypted. Enter a value only to replace it.'
                                    : 'The password is encrypted before storage and is never returned to the browser.'}
                            </small>
                        </div>
                        <div className="field">
                            <label htmlFor="mail-from-address">From email address</label>
                            <input
                                id="mail-from-address"
                                type="email"
                                value={emailForm.data.from_address}
                                onChange={(event) => emailForm.setData('from_address', event.target.value)}
                                placeholder="ats@education.go.ug"
                            />
                        </div>
                        <div className="field">
                            <label htmlFor="mail-from-name">From name</label>
                            <input
                                id="mail-from-name"
                                value={emailForm.data.from_name}
                                onChange={(event) => emailForm.setData('from_name', event.target.value)}
                                placeholder="MoES Assignment Tracking System"
                            />
                        </div>
                    </div>
                    {emailConfiguration.password_configured && (
                        <label className="email-clear-password">
                            <input
                                type="checkbox"
                                checked={emailForm.data.clear_password}
                                onChange={(event) => emailForm.setData('clear_password', event.target.checked)}
                            />
                            Remove the stored SMTP password when saving
                        </label>
                    )}
                    <div className="email-settings-security-note">
                        <ShieldCheck aria-hidden="true" />
                        <span>Email delivery failures are recorded without cancelling the assignment or correspondence action.</span>
                    </div>
                    <button type="submit" className="btn btn-primary" disabled={emailForm.processing}>
                        <Check aria-hidden="true" /> {emailForm.processing ? 'Saving…' : 'Save email configuration'}
                    </button>
                </form>
                <form className="card email-test-card" onSubmit={sendTest}>
                    <div className="card-hd">
                        <h3>
                            <MailCheck aria-hidden="true" /> Test email delivery
                        </h3>
                    </div>
                    <p>Save the SMTP configuration first, then send a test message to confirm connectivity and credentials.</p>
                    <FormErrorSummary errors={testForm.errors} />
                    <div className="email-test-row">
                        <input
                            type="email"
                            value={testForm.data.recipient}
                            onChange={(event) => testForm.setData('recipient', event.target.value)}
                            placeholder="recipient@education.go.ug"
                            aria-label="Test recipient email"
                        />
                        <button type="submit" className="btn btn-ghost" disabled={testForm.processing || !testForm.data.recipient}>
                            <Send aria-hidden="true" /> {testForm.processing ? 'Sending…' : 'Send test'}
                        </button>
                    </div>
                </form>
                <div className="card">
                    <div className="card-hd">
                        <h3>Demo Data</h3>
                    </div>
                    <p style={{ fontSize: 13, lineHeight: 1.55, margin: 0 }}>
                        Purging removes every task, history entry, evidence record, and notification. It is intended only for controlled
                        pre-production migrations and is <strong>disabled by default</strong>.
                    </p>
                    {purgeEnabled ? (
                        <button type="button" className="btn btn-ghost" style={{ marginTop: 14, color: 'var(--err)' }} onClick={requestPurge}>
                            <AlertCircle aria-hidden="true" />
                            Purge Demo Data
                        </button>
                    ) : (
                        <div className="annotation" style={{ marginTop: 14 }}>
                            Disabled in this environment. Set <span className="ref">ATS_ALLOW_DEMO_PURGE=true</span> only for a controlled migration,
                            never in production.
                        </div>
                    )}
                </div>
            </div>
        </AppShell>
    );
}
