import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import Pagination, { type PaginationMeta } from '@/components/ats/pagination';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock3, Inbox, Info, MessageSquareText, Paperclip, Search, Send, UsersRound } from 'lucide-react';
import { useState } from 'react';

interface CorrespondenceItem {
    id: number;
    register_number: string;
    subject: string;
    sender_name: string;
    recipient_display: string;
    mail_date_label: string;
    status: string;
    status_class: string;
    priority: string;
    record_kind: string;
    last_activity_label: string | null;
    forwarded_at_label: string | null;
    originating_office: string;
    action_required: boolean;
    due_date_label: string | null;
    updates_count: number;
    attachments_count: number;
    to_recipients: string[];
    cc_recipients: string[];
    my_recipient_type: 'to' | 'cc' | null;
    url: string;
}

interface Props {
    q: string;
    view: string;
    counts: Record<string, number>;
    items: { data: CorrespondenceItem[]; meta: PaginationMeta };
}

const views = [
    { key: 'all', label: 'All', icon: Inbox },
    { key: 'action', label: 'Action required', icon: Clock3 },
    { key: 'cc', label: 'CC / Information', icon: Info },
    { key: 'sent', label: 'Sent', icon: Send },
    { key: 'awaiting_response', label: 'Awaiting response', icon: UsersRound },
    { key: 'responded', label: 'Responded', icon: CheckCircle2 },
    { key: 'closed', label: 'Closed', icon: CheckCircle2 },
    { key: 'overdue', label: 'Overdue actions', icon: AlertTriangle },
];

export default function Correspondence({ q, view, counts, items }: Props) {
    const [term, setTerm] = useState(q);
    const navigate = (nextView: string, nextTerm = term) => {
        router.get(route('correspondence.index'), { view: nextView, q: nextTerm.trim() }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppShell title="Correspondence">
            <div className="page-hd correspondence-inbox-heading">
                <div>
                    <span className="result-eyebrow">Connected mail lifecycle</span>
                    <h1>Correspondence</h1>
                    <div className="page-sub">Action items, information-only copies, sent matters, responses, and closed records in one place.</div>
                </div>
            </div>

            <nav className="correspondence-view-tabs" aria-label="Correspondence views">
                {views.map((item) => {
                    const Icon = item.icon;
                    return (
                        <button key={item.key} type="button" className={view === item.key ? 'active' : ''} onClick={() => navigate(item.key)}>
                            <Icon aria-hidden="true" />
                            <span>{item.label}</span>
                            <strong>{counts[item.key] ?? 0}</strong>
                        </button>
                    );
                })}
            </nav>

            <div className="filters-bar correspondence-search-bar">
                <Search aria-hidden="true" />
                <input
                    className="input"
                    type="search"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    onKeyDown={(event) => event.key === 'Enter' && navigate(view)}
                    placeholder="Search sender, recipient, subject, reference, update text, or attachment…"
                    aria-label="Search correspondence"
                />
                <button type="button" className="btn btn-primary" onClick={() => navigate(view)}>Search</button>
                {(q || term) && <button type="button" className="btn btn-ghost" onClick={() => { setTerm(''); navigate(view, ''); }}>Clear</button>}
            </div>

            <div className="correspondence-inbox-list">
                {items.data.map((item) => (
                    <Link key={item.id} href={item.url} className="card correspondence-inbox-item">
                        <div className="correspondence-item-main">
                            <div className="correspondence-item-kicker">
                                <span>{item.register_number}</span>
                                <span>{item.record_kind}</span>
                                {item.action_required && <span className="badge st-assigned">Action required</span>}
                                {item.my_recipient_type === 'cc' && <span className="badge info">CC · Information only</span>}
                                {!item.action_required && item.my_recipient_type !== 'cc' && <span className="badge muted">No action required</span>}
                            </div>
                            <h2>{item.subject}</h2>
                            <p>From {item.sender_name}</p>
                            <p className="correspondence-origin-office">Originating office · {item.originating_office}</p>
                            <div className="correspondence-item-recipients">
                                <span><strong>To</strong>{item.to_recipients.join(', ') || item.recipient_display}</span>
                                {item.cc_recipients.length > 0 && <span><strong>CC</strong>{item.cc_recipients.join(', ')}</span>}
                            </div>
                            <div className="correspondence-item-metrics">
                                <span><Clock3 aria-hidden="true" /> Received {item.mail_date_label}</span>
                                {item.forwarded_at_label && <span><Send aria-hidden="true" /> Forwarded {item.forwarded_at_label}</span>}
                                {item.due_date_label && <span><AlertTriangle aria-hidden="true" /> Due {item.due_date_label}</span>}
                                <span><MessageSquareText aria-hidden="true" /> {item.updates_count} updates</span>
                                <span><Paperclip aria-hidden="true" /> {item.attachments_count} files</span>
                            </div>
                        </div>
                        <div className="correspondence-item-status">
                            <span className={`badge ${item.status_class}`}>{item.status}</span>
                            <small>Last activity</small>
                            <strong>{item.last_activity_label || item.mail_date_label}</strong>
                            <span className="correspondence-open-link">Open correspondence →</span>
                        </div>
                    </Link>
                ))}
            </div>

            {items.data.length === 0 && (
                <div className="card"><EmptyState>No correspondence matches this view.</EmptyState></div>
            )}
            <Pagination meta={items.meta} only={['q', 'view', 'counts', 'items']} />
        </AppShell>
    );
}
