import type { MailRow } from './index';

export interface BasicRoute {
    from: string;
    to: string;
    originalFrom: string | null;
    forwarded: boolean;
    date: string;
}

export function basicRoute(mail: MailRow, registerDirection: 'incoming' | 'outgoing' | 'filed'): BasicRoute {
    const labels = mail.basic_route_display;
    const originalFrom = labels?.original_from || mail.provenance?.original_source || mail.sender_display || mail.sender_name;
    const originalTo = labels?.original_to || mail.addressee_display || mail.recipient_name;
    const latest = registerDirection === 'outgoing' ? mail.provenance?.latest_forward : null;
    const forwarded = latest !== null && latest !== undefined;
    const from = (forwarded ? labels?.forward_from || labels?.received_by : null) || latest?.from || originalFrom;
    const to =
        (forwarded ? labels?.forward_to?.filter(Boolean).join(', ') : null) ||
        (latest?.to_display?.length ? latest.to_display : latest?.to)?.filter(Boolean).join(', ') ||
        (forwarded ? mail.recipient_display : originalTo);

    return {
        from,
        to,
        originalFrom: forwarded && originalFrom !== from ? originalFrom : null,
        forwarded,
        date: (forwarded ? latest?.forwarded_at_label : null) || mail.mail_date_label,
    };
}

export function BasicRouteFrom({ route }: { route: BasicRoute }) {
    return (
        <span className="basic-route-from">
            <span>{route.from}</span>
            {route.originalFrom && <small className="basic-route-original">Original from: {route.originalFrom}</small>}
        </span>
    );
}
