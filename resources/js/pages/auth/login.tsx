import PasswordInput from '@/components/ats/password-input';
import ThemeSelector from '@/components/ats/theme-selector';
import { LogIn } from '@/components/icons';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

interface Props {
    status: string | null;
}

export default function Login({ status }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        username: '',
        password: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('login'));
    };

    return (
        <div className="login-wrap">
            <Head title="Sign in" />
            <div className="auth-theme-picker">
                <ThemeSelector compact />
            </div>
            <div className="login-hero">
                <img src="/images/moes-crest.jpg" alt="MoES crest" />
                <h1>Assignment Tracking System</h1>
                <p>
                    Ministry of Education and Sports — Republic of Uganda. A central register for assignment ownership, progress, evidence, and
                    accountability.
                </p>
            </div>
            <div className="login-form-wrap">
                <form className="login-form" onSubmit={submit}>
                    <div>
                        <h2>Sign in</h2>
                    </div>
                    {status && (
                        <div className="page-sub" style={{ color: 'var(--succ)' }}>
                            {status}
                        </div>
                    )}
                    <div className="field">
                        <label htmlFor="username">Staff ID, Username or Email</label>
                        <input
                            id="username"
                            type="text"
                            placeholder="e.g. jkaggwa or firstname.lastname@education.go.ug"
                            autoComplete="username"
                            autoFocus
                            value={data.username}
                            onChange={(event) => setData('username', event.target.value)}
                        />
                        {errors.username && <div className="field-error">{errors.username}</div>}
                    </div>
                    <div className="field">
                        <label htmlFor="password">Password</label>
                        <PasswordInput
                            id="password"
                            placeholder="••••••••"
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                        />
                        {errors.password && <div className="field-error">{errors.password}</div>}
                    </div>
                    <button type="submit" className="btn btn-primary" style={{ justifyContent: 'center' }} disabled={processing}>
                        <LogIn aria-hidden="true" />
                        Sign In
                    </button>
                    <div style={{ fontSize: 11, color: 'var(--label)', textAlign: 'center', marginTop: 4 }}>
                        Authorised use only. All access is logged and audited.
                    </div>
                </form>
            </div>
        </div>
    );
}
