import { router, useForm } from '@inertiajs/react';
import { AlertCircle, Check } from 'lucide-react';
import type { FormEvent } from 'react';
import AppShell from '@/components/ats/app-shell';
import FormErrorSummary from '@/components/ats/form-error-summary';
import { useConfirm } from '@/hooks/use-confirm';

interface Props {
    branding: {
        ministry_full_name: string;
        ministry_short_name: string;
        system_title: string;
    };
    purgeEnabled: boolean;
}

export default function Settings({ branding, purgeEnabled }: Props) {
    const { data, setData, post, processing, errors } = useForm(branding);
    const confirm = useConfirm();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.settings.update'));
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
                    <div className="page-sub">Ministry branding and system configuration</div>
                </div>
            </div>
            <div className="grid2">
                <form className="card" onSubmit={submit}>
                    <div className="card-hd">
                        <h3>Ministry Branding</h3>
                    </div>
                    <FormErrorSummary errors={errors} />
                    <div className="field">
                        <label htmlFor="st-full">Ministry Full Name</label>
                        <input id="st-full" type="text" value={data.ministry_full_name} onChange={(event) => setData('ministry_full_name', event.target.value)} />
                        {errors.ministry_full_name && <div className="field-error">{errors.ministry_full_name}</div>}
                    </div>
                    <div className="field" style={{ marginTop: 12 }}>
                        <label htmlFor="st-short">Ministry Short Name</label>
                        <input id="st-short" type="text" value={data.ministry_short_name} onChange={(event) => setData('ministry_short_name', event.target.value)} />
                        {errors.ministry_short_name && <div className="field-error">{errors.ministry_short_name}</div>}
                    </div>
                    <div className="field" style={{ marginTop: 12 }}>
                        <label htmlFor="st-title">System Title</label>
                        <input id="st-title" type="text" value={data.system_title} onChange={(event) => setData('system_title', event.target.value)} />
                        {errors.system_title && <div className="field-error">{errors.system_title}</div>}
                    </div>
                    <button type="submit" className="btn btn-primary" style={{ marginTop: 16 }} disabled={processing}>
                        <Check aria-hidden="true" />
                        Save Branding
                    </button>
                </form>
                <div className="card">
                    <div className="card-hd">
                        <h3>Demo Data</h3>
                    </div>
                    <p style={{ fontSize: 13, lineHeight: 1.55, margin: 0 }}>
                        Purging removes every task, history entry, evidence record, and notification. It is intended
                        only for controlled pre-production migrations and is <strong>disabled by default</strong>.
                    </p>
                    {purgeEnabled ? (
                        <button type="button" className="btn btn-ghost" style={{ marginTop: 14, color: 'var(--err)' }} onClick={requestPurge}>
                            <AlertCircle aria-hidden="true" />
                            Purge Demo Data
                        </button>
                    ) : (
                        <div className="annotation" style={{ marginTop: 14 }}>
                            Disabled in this environment. Set <span className="ref">ATS_ALLOW_DEMO_PURGE=true</span>{' '}
                            only for a controlled migration, never in production.
                        </div>
                    )}
                </div>
            </div>
        </AppShell>
    );
}
