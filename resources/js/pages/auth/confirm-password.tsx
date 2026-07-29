import { Head, useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import type { FormEvent } from 'react';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors } = useForm({
        password: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('password.confirm'));
    };

    return (
        <div className="login-wrap">
            <Head title="Confirm password" />
            <div className="login-hero">
                <img src="/images/moes-crest.jpg" alt="MoES crest" />
                <h1>Assignment Tracking System</h1>
                <p>
                    Ministry of Education and Sports — Republic of Uganda. A central register for
                    assignment ownership, progress, evidence, and accountability.
                </p>
            </div>
            <div className="login-form-wrap">
                <form className="login-form" onSubmit={submit}>
                    <div>
                        <h2>Confirm password</h2>
                        <div className="page-sub">
                            This is a secure area. Please confirm your password before continuing.
                        </div>
                    </div>
                    <div className="field">
                        <label htmlFor="password">Password</label>
                        <input
                            id="password"
                            type="password"
                            placeholder="••••••••"
                            autoComplete="current-password"
                            autoFocus
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                        />
                        {errors.password && <div className="field-error">{errors.password}</div>}
                    </div>
                    <button type="submit" className="btn btn-primary" style={{ justifyContent: 'center' }} disabled={processing}>
                        <Check aria-hidden="true" />
                        Confirm
                    </button>
                </form>
            </div>
        </div>
    );
}
