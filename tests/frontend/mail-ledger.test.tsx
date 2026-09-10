import { MailLedgerStatus, MailLedgerSubjectLink } from '@/components/ats/mail-ledger';
import MailRegisterHeading from '@/components/ats/mail-register-heading';
import { render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        preserveScroll: _preserveScroll,
        preserveState: _preserveState,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; preserveScroll?: boolean; preserveState?: boolean }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

describe('mail register ledger elements', () => {
    it('separates incoming register direction from its correspondence descriptor', () => {
        render(<MailRegisterHeading direction="incoming" officeName="Office of the Permanent Secretary" />);

        const heading = screen.getByRole('heading', { name: 'Active Incoming Correspondence' });
        expect(heading).toHaveClass('mail-register-title');
        expect(within(heading).getByText('Active Incoming')).toHaveClass('mail-register-title-primary');
        expect(within(heading).getByText('Correspondence')).toHaveClass('mail-register-title-secondary');
        expect(screen.getByText('Office of the Permanent Secretary')).toHaveClass('mail-register-office-reference');
    });

    it('uses a typographic separator instead of a slash in the outgoing register heading', () => {
        render(<MailRegisterHeading direction="outgoing" officeName="PS" />);

        expect(screen.getByRole('heading', { name: 'Outgoing and Forwarded Correspondence' })).toBeInTheDocument();
        expect(screen.queryByText(/Outgoing \/ Forwarded/)).not.toBeInTheDocument();
    });

    it('presents the subject as the single clearly actionable link', () => {
        render(<MailLedgerSubjectLink href="/mail/41">Review the education sector report</MailLedgerSubjectLink>);

        const link = screen.getByRole('link', { name: 'Review the education sector report' });
        expect(link).toHaveAttribute('href', '/mail/41');
        expect(link).toHaveClass('mail-ledger-subject-link');
    });

    it('presents status as a dot and text instead of a pill', () => {
        const { container } = render(<MailLedgerStatus label="Received" tone="st-received" />);

        expect(screen.getByText('Received')).toBeInTheDocument();
        expect(container.querySelector('.mail-ledger-status-dot')).toHaveAttribute('aria-hidden', 'true');
        expect(container.querySelector('.badge')).not.toBeInTheDocument();
    });
});
