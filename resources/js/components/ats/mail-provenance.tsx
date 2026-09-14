export interface MailProvenanceData {
    original_source: string;
    original_source_organisation?: string | null;
    original_addressee: string;
    received_by: string;
    received_through: string[];
    current_locations: string[];
    current_handlers?: string[];
    latest_forward: { from: string | null; to: string[]; by: string; forwarded_at: string | null; forwarded_at_label: string | null } | null;
}

export interface MailMovementEvent {
    id: string;
    type: string;
    at: string | null;
    at_label: string | null;
    from: string | null;
    to: string | null;
    by: string | null;
    instructions: string | null;
}

export function MailOriginCell({ provenance, fallback }: { provenance?: MailProvenanceData; fallback: string }) {
    return (
        <div className="mail-origin-cell">
            <span>{provenance?.original_source ?? fallback}</span>
            {provenance?.latest_forward && <small>Received through: {provenance.received_by}</small>}
        </div>
    );
}

export default function MailProvenance({ provenance, events = [] }: { provenance?: MailProvenanceData; events?: MailMovementEvent[] }) {
    if (!provenance) return null;
    const latest = provenance.latest_forward;
    const facts = [
        ['Original source', provenance.original_source],
        ...(provenance.original_source_organisation && provenance.original_source_organisation !== provenance.original_source
            ? [['Source organisation', provenance.original_source_organisation]]
            : []),
        ['Originally addressed to', provenance.original_addressee],
        ['First received by', provenance.received_by],
        ['Current location', provenance.current_locations.join(', ') || 'No active handling destination recorded'],
        ...(provenance.current_handlers?.length ? [['Responsible officer', provenance.current_handlers.join(', ')]] : []),
        ...(latest
            ? [
                  ['Forwarded from', latest.from || 'Office not recorded'],
                  ['Forwarded to', latest.to.join(', ')],
                  ['Forwarded by', latest.by],
                  ['Date forwarded', latest.forwarded_at_label || 'Not recorded'],
              ]
            : []),
    ];
    return (
        <section className="mail-provenance" aria-label="Mail origin and forwarding route">
            <h3>Origin and forwarding route</h3>
            <p className="mail-provenance-route">
                <strong>{provenance.original_source}</strong> → {provenance.received_by}
                {latest && <> · Current location: {provenance.current_locations.join(' / ') || 'No active handling destination'}</>}
            </p>
            {latest && (
                <p>
                    Received through: {provenance.received_by}. Forwarding offices: {provenance.received_through.join(' · ')}
                </p>
            )}
            <dl className="mail-provenance-facts">
                {facts.map(([label, value]) => (
                    <div key={label}>
                        <dt>{label}</dt>
                        <dd>{value}</dd>
                    </div>
                ))}
            </dl>
            {events.length > 0 && (
                <details className="mail-provenance-history" open>
                    <summary>
                        Mail movement history <span>({events.length})</span>
                    </summary>
                    <ol>
                        {events.map((event) => (
                            <li key={event.id}>
                                <div>
                                    <strong>{event.type.replaceAll('_', ' ')}</strong>{' '}
                                    <time dateTime={event.at ?? undefined}>{event.at_label ?? 'Date not recorded'}</time>
                                </div>
                                {(event.from || event.to) && (
                                    <p>
                                        {event.from}
                                        {event.from && event.to ? ' → ' : ''}
                                        {event.to}
                                    </p>
                                )}
                                {event.by && <p className="mail-movement-officer">Recorded by: {event.by}</p>}
                                {event.instructions && <p className="mail-movement-instruction">{event.instructions}</p>}
                            </li>
                        ))}
                    </ol>
                </details>
            )}
        </section>
    );
}
