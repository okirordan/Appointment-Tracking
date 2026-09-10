import type { ReactNode } from 'react';

export type CaseFileTone = 'amber' | 'green' | 'blue' | 'neutral';

const statusTones: Record<string, CaseFileTone> = {
    withdrawn: 'amber',
    pending: 'amber',
    awaiting_review: 'amber',
    awaiting_response: 'amber',
    action_required: 'amber',
    under_review: 'amber',
    active: 'amber',
    assigned: 'amber',
    forwarded: 'amber',
    responded: 'green',
    response_received: 'green',
    completed: 'green',
    closed: 'green',
    filed: 'green',
    incoming: 'blue',
    received: 'blue',
    registered: 'blue',
};

export function correspondenceStatusTone(status: string | null | undefined): CaseFileTone {
    const statusKey = (status ?? '').toLowerCase().trim().replaceAll(' ', '_');

    return statusTones[statusKey] ?? 'neutral';
}

export function CaseFileLetterhead({
    recordKind,
    reference,
    subject,
    status,
    tone,
    children,
}: {
    recordKind: string;
    reference?: string | null;
    subject: string;
    status: string;
    tone: CaseFileTone;
    children?: ReactNode;
}) {
    return (
        <div className="case-file-letterhead">
            <p className="case-file-register-name">Official correspondence register</p>
            <p className="case-file-reference-line">
                <span>{recordKind}</span>
                <span aria-hidden="true">·</span>
                <span>
                    Reference: <strong>{reference || 'Pending registration'}</strong>
                </span>
            </p>
            <div className="case-file-title-row">
                <h2>{subject}</h2>
                <span className={`case-file-status-stamp tone-${tone}`} role="status" aria-label={`Correspondence status: ${status}`}>
                    {status}
                </span>
            </div>
            {children && <div className="case-file-letterhead-meta">{children}</div>}
        </div>
    );
}
