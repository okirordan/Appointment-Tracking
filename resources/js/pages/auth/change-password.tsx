import AppShell from '@/components/ats/app-shell';
import FormErrorSummary from '@/components/ats/form-error-summary';
import PasswordInput from '@/components/ats/password-input';
import { Check } from '@/components/icons';
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

interface Props {
    forced: boolean;
}

export default function ChangePassword({ forced }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('password.change.store'), {
            onError: () => reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <AppShell title="Change Password">
            <div className="page-hd">
                <div>
                    <h1>Change Password</h1>
                    <div className="page-sub">
                        {forced
                            ? 'You must set a new password before continuing.'
                            : 'Use at least 8 characters with upper and lower case letters, a number, and a symbol.'}
                    </div>
                </div>
            </div>
            <form className="card" style={{ maxWidth: 480 }} onSubmit={submit}>
                <FormErrorSummary errors={errors} />
                <div className="field">
                    <label htmlFor="cp-current">Current Password</label>
                    <PasswordInput
                        id="cp-current"
                        autoComplete="current-password"
                        value={data.current_password}
                        onChange={(event) => setData('current_password', event.target.value)}
                    />
                    {errors.current_password && <div className="field-error">{errors.current_password}</div>}
                </div>
                <div className="field" style={{ marginTop: 14 }}>
                    <label htmlFor="cp-new">New Password</label>
                    <PasswordInput
                        id="cp-new"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                    />
                    {errors.password && <div className="field-error">{errors.password}</div>}
                </div>
                <div className="field" style={{ marginTop: 14 }}>
                    <label htmlFor="cp-confirm">Confirm New Password</label>
                    <PasswordInput
                        id="cp-confirm"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(event) => setData('password_confirmation', event.target.value)}
                    />
                </div>
                <button type="submit" className="btn btn-primary" style={{ marginTop: 16 }} disabled={processing}>
                    <Check aria-hidden="true" />
                    Update Password
                </button>
            </form>
        </AppShell>
    );
}
