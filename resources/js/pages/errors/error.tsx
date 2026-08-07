import { AlertCircle } from '@/components/icons';
import { Head, router } from '@inertiajs/react';

interface Props {
    status: number;
    message?: string | null;
}

interface ErrorCopy {
    title: string;
    body: string;
}

const COPY: Record<number, ErrorCopy> = {
    403: {
        title: 'Access denied',
        body: 'You do not have permission to view this page. If you believe this is a mistake, contact your administrator.',
    },
    404: {
        title: 'Page not found',
        body: 'The page or record you are looking for does not exist, or it may have been moved.',
    },
    419: {
        title: 'Session expired',
        body: 'Your session timed out for security. Please sign in again and retry what you were doing.',
    },
    500: {
        title: 'Something went wrong',
        body: 'An unexpected error occurred on our side. The technical details have been logged for our team.',
    },
    503: {
        title: 'Temporarily unavailable',
        body: 'The system is undergoing brief maintenance. Please try again in a few minutes.',
    },
};

export default function ErrorPage({ status, message }: Props) {
    const copy = COPY[status] ?? {
        title: 'Unexpected error',
        body: 'Something did not work as expected. Please try again.',
    };

    const canRetry = status === 500 || status === 503;

    return (
        <div
            style={{
                minHeight: 0,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: 24,
                background: 'var(--page)',
            }}
        >
            <Head title={`${status} — ${copy.title}`} />
            <div className="card" style={{ maxWidth: 460, textAlign: 'center', padding: '36px 32px' }}>
                <div
                    style={{
                        width: 56,
                        height: 56,
                        borderRadius: '50%',
                        background: 'var(--pri50)',
                        color: 'var(--pri)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        margin: '0 auto 18px',
                    }}
                >
                    <AlertCircle aria-hidden="true" style={{ width: 26, height: 26 }} />
                </div>
                <div
                    style={{
                        fontFamily: "'Poppins', sans-serif",
                        fontWeight: 700,
                        fontSize: 32,
                        color: 'var(--title)',
                        lineHeight: 1,
                    }}
                >
                    {status}
                </div>
                <h1 style={{ fontSize: 20, marginTop: 10 }}>{copy.title}</h1>
                <p style={{ fontSize: 13, color: 'var(--label)', lineHeight: 1.55, marginTop: 8 }}>{message || copy.body}</p>
                <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 22, flexWrap: 'wrap' }}>
                    <button type="button" className="btn btn-ghost" onClick={() => window.history.back()}>
                        Go back
                    </button>
                    {canRetry ? (
                        <button type="button" className="btn btn-primary" onClick={() => router.reload()}>
                            Try again
                        </button>
                    ) : (
                        <button type="button" className="btn btn-primary" onClick={() => router.visit('/home')}>
                            Return home
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
