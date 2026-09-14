import MailProvenance, { MailOriginCell, type MailProvenanceData } from '@/components/ats/mail-provenance';
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

const provenance: MailProvenanceData = {
    original_source: 'Ministry of Finance',
    original_addressee: 'Permanent Secretary, Ministry of Education and Sports',
    received_by: 'Office of the Permanent Secretary',
    received_through: ['Office of the Permanent Secretary'],
    current_locations: ['LEIT Department'],
    latest_forward: {
        from: 'Office of the Permanent Secretary',
        to: ['LEIT Department'],
        by: 'Jane Namusoke',
        forwarded_at: '2026-09-10T09:00:00+03:00',
        forwarded_at_label: '10/09/2026 09:00',
    },
};

describe('mail origin and routing', () => {
    it('keeps original sender, original addressee, forwarding office and actual officer separately labelled', () => {
        render(<MailProvenance provenance={provenance} />);
        for (const [label, value] of [
            ['Original source', 'Ministry of Finance'],
            ['Originally addressed to', provenance.original_addressee],
            ['Forwarded from', provenance.received_by],
            ['Forwarded by', 'Jane Namusoke'],
            ['Current location', 'LEIT Department'],
        ]) {
            expect(screen.getByText(label).nextElementSibling).toHaveTextContent(value);
        }
    });

    it('shows original source and received-through context in register cells even for old outgoing copies', () => {
        render(<MailOriginCell provenance={provenance} fallback="PS Office" />);
        expect(screen.getByText('Ministry of Finance')).toBeVisible();
        expect(screen.getByText('Received through: Office of the Permanent Secretary')).toBeVisible();
        expect(screen.queryByText('PS Office')).not.toBeInTheDocument();
    });

    it('renders chronological movements with the actual actor and safely displays instructions as text', () => {
        render(
            <MailProvenance
                provenance={provenance}
                events={[
                    {
                        id: '1',
                        type: 'registered',
                        at: '2026-09-10T08:00:00+03:00',
                        at_label: '10/09/2026 08:00',
                        from: 'Ministry of Finance',
                        to: provenance.received_by,
                        by: 'Registry Clerk',
                        instructions: null,
                    },
                    {
                        id: '2',
                        type: 'forwarded',
                        at: '2026-09-10T09:00:00+03:00',
                        at_label: '10/09/2026 09:00',
                        from: provenance.received_by,
                        to: 'LEIT Department',
                        by: 'Jane Namusoke',
                        instructions: '<script>not executable</script>',
                    },
                ]}
            />,
        );
        const events = screen.getAllByRole('listitem');
        expect(within(events[0]).getByText('registered')).toBeVisible();
        expect(within(events[1]).getByText('Recorded by: Jane Namusoke')).toBeVisible();
        expect(events[1].querySelector('script')).toBeNull();
    });
});
