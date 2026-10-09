import { useForm } from '@inertiajs/react';
import type { MailDetail } from './index';
import { useMailUrl } from './mode';

/** The same status endpoint and review restrictions serve both presentations. */
export default function MailStatusForm({ mail }: { mail: MailDetail }) {
    const url = useMailUrl();
    const form = useForm({ status: mail.status_value, note: '', dispatch_method: '', dispatch_reference: '', dispatched_at: '' });
    if (!mail.can_edit || !mail.transition_options?.length) return null;
    return (
        <details className="basic-extras mail-status-options">
            <summary>Status and dispatch</summary>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(url(route('mail.transition', mail.id), { view: null }), { preserveScroll: true });
                }}
            >
                {Object.keys(form.errors).length > 0 && (
                    <div role="alert">
                        {Object.values(form.errors).map((error, index) => (
                            <p key={index} className="field-error">
                                {error}
                            </p>
                        ))}
                    </div>
                )}
                <div className="mail-form-grid grid">
                    <label className="field">
                        Status
                        <select className="select" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                            {mail.transition_options.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="field">
                        Note
                        <textarea
                            className="textarea"
                            maxLength={2000}
                            value={form.data.note}
                            onChange={(event) => form.setData('note', event.target.value)}
                        />
                    </label>
                    {form.data.status === 'dispatched' && (
                        <>
                            <label className="field">
                                Dispatch method
                                <input
                                    className="input"
                                    maxLength={50}
                                    value={form.data.dispatch_method}
                                    onChange={(event) => form.setData('dispatch_method', event.target.value)}
                                />
                            </label>
                            <label className="field">
                                Dispatch reference
                                <input
                                    className="input"
                                    maxLength={255}
                                    value={form.data.dispatch_reference}
                                    onChange={(event) => form.setData('dispatch_reference', event.target.value)}
                                />
                            </label>
                            <label className="field">
                                Date dispatched
                                <input
                                    className="input"
                                    type="date"
                                    value={form.data.dispatched_at}
                                    onChange={(event) => form.setData('dispatched_at', event.target.value)}
                                />
                            </label>
                        </>
                    )}
                </div>
                <button className="btn p btn-primary" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Update status'}
                </button>
            </form>
        </details>
    );
}
