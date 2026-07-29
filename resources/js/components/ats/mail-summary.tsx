import { Link } from '@inertiajs/react';
import { Archive, ArrowRight, ClipboardCheck, Clock3, FileEdit, Inbox, MailCheck, Send } from 'lucide-react';

interface MailSummaryStats {
    incoming_total: number;
    awaiting_assignment: number;
    assigned_total: number;
    active_assignments: number;
    outgoing_total: number;
    drafts?: number;
    awaiting_review?: number;
    completed_archived?: number;
}

interface MailSummaryProps {
    stats: MailSummaryStats;
    className?: string;
    showOutgoing?: boolean;
}

interface SummaryItem {
    key: keyof MailSummaryStats;
    label: string;
    routeName: string;
    routeParams?: { status: string };
    icon: typeof Inbox;
    tone: string;
}

const summaryItems: SummaryItem[] = [
    {
        key: 'incoming_total',
        label: 'Incoming recorded',
        routeName: 'mail.incoming.index',
        icon: Inbox,
        tone: '',
    },
    {
        key: 'awaiting_assignment',
        label: 'Awaiting assignment',
        routeName: 'mail.incoming.index',
        routeParams: { status: 'unassigned' },
        icon: MailCheck,
        tone: 'amber',
    },
    {
        key: 'assigned_total',
        label: 'Assigned for action',
        routeName: 'mail.incoming.index',
        routeParams: { status: 'assigned_any' },
        icon: ClipboardCheck,
        tone: 'blue',
    },
    {
        key: 'active_assignments',
        label: 'Active follow-ups',
        routeName: 'mail.incoming.index',
        routeParams: { status: 'assigned' },
        icon: ArrowRight,
        tone: 'green',
    },
];

const outgoingItem: SummaryItem = {
    key: 'outgoing_total',
    label: 'Outgoing',
    routeName: 'mail.outgoing.index',
    icon: Send,
    tone: 'amber',
};

const correspondenceItems: SummaryItem[] = [
    summaryItems[0],
    outgoingItem,
    { key: 'drafts', label: 'Draft correspondence', routeName: 'mail.outgoing.index', routeParams: { status: 'draft' }, icon: FileEdit, tone: 'amber' },
    { key: 'awaiting_review', label: 'Awaiting PS review', routeName: 'mail.outgoing.index', routeParams: { status: 'awaiting_review' }, icon: Clock3, tone: 'blue' },
    { key: 'assigned_total', label: 'Forwarded or assigned', routeName: 'mail.incoming.index', routeParams: { status: 'assigned' }, icon: ClipboardCheck, tone: 'green' },
    { key: 'completed_archived', label: 'Completed or archived', routeName: 'mail.incoming.index', routeParams: { status: 'archived' }, icon: Archive, tone: '' },
];

export default function MailSummary({ stats, className = '', showOutgoing = false }: MailSummaryProps) {
    const items = showOutgoing ? correspondenceItems : summaryItems;

    return (
        <section className={`mail-stat-grid ${className}`.trim()} aria-label="Mail summary">
            {items.map((item) => {
                const Icon = item.icon;

                return (
                    <Link
                        key={item.key}
                        className={`mail-stat mail-stat-link ${item.tone}`.trim()}
                        href={route(item.routeName, item.routeParams)}
                        aria-label={`${item.label}: ${stats[item.key] ?? 0}`}
                    >
                        <span>
                            <Icon aria-hidden="true" />
                        </span>
                        <div>
                            <strong>{stats[item.key] ?? 0}</strong>
                            <small>{item.label}</small>
                        </div>
                    </Link>
                );
            })}
        </section>
    );
}
