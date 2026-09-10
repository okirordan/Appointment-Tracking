import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export function MailLedgerSubjectLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link className="mail-ledger-subject-link" href={href} preserveScroll preserveState>
            {children}
        </Link>
    );
}

export function MailLedgerStatus({ label, tone }: { label: string; tone: string }) {
    return (
        <span className={`mail-ledger-status ${tone}`}>
            <span className="mail-ledger-status-dot" aria-hidden="true" />
            <span>{label}</span>
        </span>
    );
}
