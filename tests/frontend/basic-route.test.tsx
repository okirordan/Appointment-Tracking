import { basicRoute, BasicRouteFrom } from '@/pages/mail/basic-route';
import type { MailRow } from '@/pages/mail/index';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

const mail = {
    direction: 'incoming',
    mailbox_direction: 'outgoing',
    record_kind: 'Outgoing · Forwarded',
    sender_name: 'Ministry of Finance',
    sender_display: 'Ministry of Finance',
    recipient_name: 'Permanent Secretary',
    addressee_display: 'PS/ES',
    recipient_display: 'C/HRM',
    mail_date_label: '13/09/2026',
    provenance: {
        original_source: 'Ministry of Finance',
        original_addressee: 'Permanent Secretary',
        received_by: 'Office of the Permanent Secretary',
        received_through: ['Office of the Permanent Secretary'],
        current_locations: ['C/HRM'],
        latest_forward: {
            from: 'Office of the Permanent Secretary',
            to: ['C/HRM'],
            to_display: ['C/HRM'],
            by: 'Secretary',
            forwarded_at: null,
            forwarded_at_label: '12/09/2026 09:00',
        },
    },
} as MailRow;

it('shows the current forwarding movement on Outgoing and the original sender in the background', () => {
    const route = basicRoute(mail, 'outgoing');
    expect(route).toMatchObject({
        from: 'Office of the Permanent Secretary',
        to: 'C/HRM',
        originalFrom: 'Ministry of Finance',
        forwarded: true,
        date: '12/09/2026 09:00',
    });
    render(<BasicRouteFrom route={route} />);
    expect(screen.getByText('Office of the Permanent Secretary')).toBeVisible();
    expect(screen.getByText('Original from: Ministry of Finance')).toBeVisible();
});

it('keeps the original source and addressee on Incoming', () => {
    expect(basicRoute(mail, 'incoming')).toMatchObject({
        from: 'Ministry of Finance',
        to: 'PS/ES',
        originalFrom: null,
        forwarded: false,
        date: '13/09/2026',
    });
});

it('keeps directly logged Outgoing mail on its recorded route', () => {
    expect(
        basicRoute(
            { ...mail, direction: 'outgoing', record_kind: 'Outgoing', provenance: { ...mail.provenance!, latest_forward: null } },
            'outgoing',
        ),
    ).toMatchObject({
        from: 'Ministry of Finance',
        to: 'PS/ES',
        originalFrom: null,
        forwarded: false,
    });
});

it('keeps the original sender behind the latest office-to-office movement', () => {
    const next = basicRoute(
        {
            ...mail,
            provenance: {
                ...mail.provenance!,
                latest_forward: {
                    ...mail.provenance!.latest_forward!,
                    from: 'Human Resource Management',
                    to: ['Office of the Commissioner — Library'],
                    to_display: ['C/LEIT'],
                },
            },
        },
        'outgoing',
    );
    expect(next).toMatchObject({ from: 'Human Resource Management', to: 'C/LEIT', originalFrom: 'Ministry of Finance' });
});
