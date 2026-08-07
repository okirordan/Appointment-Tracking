import ThemeSelector from '@/components/ats/theme-selector';
import { KeyRound, ShieldCheck } from '@/components/icons';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

export default function TwoFactorChallenge() {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);
    const { data, setData, post, processing, errors, clearErrors, reset } = useForm({
        code: '',
        recovery_code: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('two-factor.login.store'));
    };

    const toggleMode = () => {
        setUseRecoveryCode((current) => !current);
        clearErrors();
        reset('code', 'recovery_code');
    };

    return (
        <div className="login-wrap">
            <Head title="Two-factor verification" />
            <div className="auth-theme-picker">
                <ThemeSelector compact />
            </div>
            <div className="login-hero">
                <img src="/images/moes-crest.jpg" alt="MoES crest" />
                <h1>Assignment Tracking System</h1>
                <p>Your password was accepted. Complete the second verification step to open your secure workspace.</p>
            </div>
            <div className="login-form-wrap">
                <form className="login-form two-factor-challenge-form" onSubmit={submit}>
                    <span className="security-icon" aria-hidden="true">
                        <ShieldCheck />
                    </span>
                    <div>
                        <h2>Verify it’s you</h2>
                        <div className="page-sub">
                            {useRecoveryCode
                                ? 'Enter one of the recovery codes you saved when enabling two-factor authentication.'
                                : 'Enter the six-digit code from your authenticator app.'}
                        </div>
                    </div>
                    {useRecoveryCode ? (
                        <div className="field">
                            <label htmlFor="recovery-code">Recovery code</label>
                            <input
                                id="recovery-code"
                                value={data.recovery_code}
                                onChange={(event) => setData('recovery_code', event.target.value)}
                                autoComplete="one-time-code"
                                autoFocus
                            />
                            {errors.recovery_code && <div className="field-error">{errors.recovery_code}</div>}
                        </div>
                    ) : (
                        <div className="field">
                            <label htmlFor="two-factor-code">Authentication code</label>
                            <input
                                id="two-factor-code"
                                className="two-factor-code-input"
                                value={data.code}
                                onChange={(event) => setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                pattern="[0-9]*"
                                maxLength={6}
                                autoFocus
                            />
                            {errors.code && <div className="field-error">{errors.code}</div>}
                        </div>
                    )}
                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={processing || (useRecoveryCode ? data.recovery_code.trim() === '' : data.code.length !== 6)}
                    >
                        <KeyRound aria-hidden="true" />
                        Verify and sign in
                    </button>
                    <button type="button" className="text-action" onClick={toggleMode}>
                        {useRecoveryCode ? 'Use an authenticator code' : 'Use a recovery code instead'}
                    </button>
                </form>
            </div>
        </div>
    );
}
