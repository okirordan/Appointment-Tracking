import AppShell from '@/components/ats/app-shell';
import { SearchLoader } from '@/components/ats/search-loader';
import { router, useForm } from '@inertiajs/react';
import { Check, Copy, KeyRound, RefreshCw, ShieldCheck, ShieldOff } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';

interface Props {
    twoFactor: {
        enabled: boolean;
        pending: boolean;
    };
    status: string | null;
}

const statusMessages: Record<string, string> = {
    'two-factor-authentication-enabled': 'Scan the QR code, then confirm a code to finish setup.',
    'two-factor-authentication-confirmed': 'Two-factor authentication is now protecting your account.',
    'two-factor-authentication-disabled': 'Two-factor authentication has been disabled.',
    'recovery-codes-generated': 'New recovery codes were generated. Save this latest set.',
};

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) throw new Error('Unable to load the protected two-factor details.');

    return response.json() as Promise<T>;
}

export default function Security({ twoFactor, status }: Props) {
    const [qrSvg, setQrSvg] = useState('');
    const [secretKey, setSecretKey] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
    const [loadingDetails, setLoadingDetails] = useState(twoFactor.pending || twoFactor.enabled);
    const [detailsError, setDetailsError] = useState('');
    const [copied, setCopied] = useState(false);
    const confirmForm = useForm({ code: '' });

    useEffect(() => {
        let active = true;
        setDetailsError('');

        const load = async () => {
            try {
                if (twoFactor.pending) {
                    const [qr, secret] = await Promise.all([
                        getJson<{ svg: string }>(route('two-factor.qr-code')),
                        getJson<{ secretKey: string }>(route('two-factor.secret-key')),
                    ]);
                    if (active) {
                        setQrSvg(qr.svg);
                        setSecretKey(secret.secretKey);
                    }
                } else if (twoFactor.enabled) {
                    const codes = await getJson<string[]>(route('two-factor.recovery-codes'));
                    if (active) setRecoveryCodes(codes);
                }
            } catch (error) {
                if (active) setDetailsError(error instanceof Error ? error.message : 'Unable to load two-factor details.');
            } finally {
                if (active) setLoadingDetails(false);
            }
        };

        if (twoFactor.pending || twoFactor.enabled) void load();
        else setLoadingDetails(false);

        return () => {
            active = false;
        };
    }, [twoFactor.enabled, twoFactor.pending]);

    const confirm = (event: FormEvent) => {
        event.preventDefault();
        confirmForm.post(route('two-factor.confirm'), {
            errorBag: 'confirmTwoFactorAuthentication',
            preserveScroll: true,
            onSuccess: () => confirmForm.reset(),
        });
    };

    const copyRecoveryCodes = async () => {
        await navigator.clipboard.writeText(recoveryCodes.join('\n'));
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1800);
    };

    const regenerate = () => {
        router.post(
            route('two-factor.regenerate-recovery-codes'),
            {},
            {
                preserveScroll: true,
                onSuccess: async () => {
                    setLoadingDetails(true);
                    setRecoveryCodes(await getJson<string[]>(route('two-factor.recovery-codes')));
                    setLoadingDetails(false);
                },
            },
        );
    };

    return (
        <AppShell title="Account Security">
            <div className="page-hd security-page-heading">
                <div>
                    <span className="result-eyebrow">Account protection</span>
                    <h1>Security</h1>
                    <div className="page-sub">Manage your password and authenticator-based sign-in protection.</div>
                </div>
                <span className={twoFactor.enabled ? 'security-status enabled' : 'security-status'}>
                    {twoFactor.enabled ? <ShieldCheck aria-hidden="true" /> : <ShieldOff aria-hidden="true" />}
                    Two-factor {twoFactor.enabled ? 'enabled' : twoFactor.pending ? 'setup pending' : 'disabled'}
                </span>
            </div>

            {status && statusMessages[status] && (
                <div className="security-notice" role="status">
                    <Check aria-hidden="true" />
                    {statusMessages[status]}
                </div>
            )}

            <div className="security-grid">
                <section className="card security-card">
                    <div className="security-card-heading">
                        <span className="security-icon" aria-hidden="true">
                            <ShieldCheck />
                        </span>
                        <div>
                            <h2>Authenticator app</h2>
                            <p>Add a second step after your password using any TOTP-compatible authenticator app.</p>
                        </div>
                    </div>

                    {!twoFactor.enabled && !twoFactor.pending && (
                        <div className="security-action-panel">
                            <p>You’ll scan a QR code and save one-time recovery codes in case your phone is unavailable.</p>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => router.post(route('two-factor.enable'), {}, { preserveScroll: true })}
                            >
                                <KeyRound aria-hidden="true" />
                                Enable two-factor authentication
                            </button>
                        </div>
                    )}

                    {twoFactor.pending && (
                        <div className="two-factor-setup">
                            {loadingDetails ? (
                                <SearchLoader label="Preparing secure setup…" />
                            ) : detailsError ? (
                                <div className="field-error">{detailsError}</div>
                            ) : (
                                <>
                                    <div className="two-factor-steps">
                                        <span>1</span>
                                        <div>
                                            <h3>Scan this QR code</h3>
                                            <p>Open your authenticator app and add a new account.</p>
                                        </div>
                                    </div>
                                    <div className="two-factor-qr" dangerouslySetInnerHTML={{ __html: qrSvg }} />
                                    <details className="secret-key-details">
                                        <summary>Can’t scan the code?</summary>
                                        <code>{secretKey}</code>
                                    </details>
                                    <div className="two-factor-steps">
                                        <span>2</span>
                                        <div>
                                            <h3>Confirm the six-digit code</h3>
                                            <p>This confirms that your authenticator was linked correctly.</p>
                                        </div>
                                    </div>
                                    <form className="two-factor-confirm-form" onSubmit={confirm}>
                                        <div className="field">
                                            <label htmlFor="confirm-two-factor-code">Authentication code</label>
                                            <input
                                                id="confirm-two-factor-code"
                                                className="two-factor-code-input"
                                                value={confirmForm.data.code}
                                                onChange={(event) => confirmForm.setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))}
                                                inputMode="numeric"
                                                autoComplete="one-time-code"
                                                maxLength={6}
                                            />
                                            {confirmForm.errors.code && <div className="field-error">{confirmForm.errors.code}</div>}
                                        </div>
                                        <button
                                            type="submit"
                                            className="btn btn-primary"
                                            disabled={confirmForm.processing || confirmForm.data.code.length !== 6}
                                        >
                                            <Check aria-hidden="true" /> Confirm and enable
                                        </button>
                                    </form>
                                    <button
                                        type="button"
                                        className="text-action danger"
                                        onClick={() => router.delete(route('two-factor.disable'), { preserveScroll: true })}
                                    >
                                        Cancel setup
                                    </button>
                                </>
                            )}
                        </div>
                    )}

                    {twoFactor.enabled && (
                        <div className="security-action-panel">
                            <p>Your account will ask for an authenticator or recovery code after every successful password check.</p>
                            <button
                                type="button"
                                className="btn btn-ghost danger-button"
                                onClick={() => router.delete(route('two-factor.disable'), { preserveScroll: true })}
                            >
                                <ShieldOff aria-hidden="true" />
                                Disable two-factor authentication
                            </button>
                        </div>
                    )}
                </section>

                <section className="card security-card recovery-card">
                    <div className="security-card-heading">
                        <span className="security-icon secondary" aria-hidden="true">
                            <KeyRound />
                        </span>
                        <div>
                            <h2>Recovery codes</h2>
                            <p>Each code works once if you cannot access your authenticator.</p>
                        </div>
                    </div>
                    {!twoFactor.enabled ? (
                        <div className="security-muted-panel">Recovery codes become available after setup is confirmed.</div>
                    ) : loadingDetails ? (
                        <SearchLoader label="Loading recovery codes…" />
                    ) : detailsError ? (
                        <div className="field-error">{detailsError}</div>
                    ) : (
                        <>
                            <div className="recovery-code-grid">
                                {recoveryCodes.map((code) => (
                                    <code key={code}>{code}</code>
                                ))}
                            </div>
                            <div className="recovery-actions">
                                <button type="button" className="btn btn-ghost" onClick={copyRecoveryCodes}>
                                    <Copy aria-hidden="true" /> {copied ? 'Copied' : 'Copy codes'}
                                </button>
                                <button type="button" className="btn btn-ghost" onClick={regenerate}>
                                    <RefreshCw aria-hidden="true" /> Generate new codes
                                </button>
                            </div>
                            <small className="security-warning">
                                Store these somewhere private. Generating a new set invalidates every code shown before it.
                            </small>
                        </>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
