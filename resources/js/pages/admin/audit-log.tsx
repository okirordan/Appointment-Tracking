import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import Modal from '@/components/ats/modal';
import Pagination from '@/components/ats/pagination';
import type { PaginatedData } from '@/types';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface LogEntry {
    id: number;
    timestamp: string;
    actor: string;
    category: string;
    action: string;
    outcome: string;
    severity: string;
    resource: string | null;
    record_id: number | null;
    ip_address: string | null;
    details: Record<string, unknown>;
}
interface Filters {
    tab: string;
    q: string;
    category: string;
    action: string;
    actor: string;
    outcome: string;
    severity: string;
    from: string;
    to: string;
}
interface Props {
    filters: Filters;
    categories: string[];
    actions: string[];
    actors: { id: number; name: string }[];
    logs: PaginatedData<LogEntry>;
}
const tabs = [
    { id: 'users', label: 'User Activity' },
    { id: 'activity', label: 'Activity Log' },
    { id: 'system', label: 'System Logs' },
    { id: 'laravel', label: 'Laravel Errors' },
    { id: 'failed', label: 'Failed User Actions' },
];
const help: Record<string, string> = {
    users: 'Sign-ins, page access and actions performed by users.',
    activity: 'Application audit records, including record changes and administrative actions.',
    system: 'Queue jobs, scheduled tasks, warnings and operational events.',
    laravel: 'Application errors with redacted messages and technical details.',
    failed: 'User operations that were rejected or could not be completed.',
};

export default function AuditLog({ filters, categories, actions, actors, logs }: Props) {
    const [local, setLocal] = useState(filters);
    const [selected, setSelected] = useState<LogEntry | null>(null);
    useEffect(() => setLocal(filters), [filters]);
    const apply = (next = local) => {
        setSelected(null);
        router.get(route('admin.audit.index'), Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '')), { preserveState: true });
    };
    const field = (key: keyof Filters, value: string) => setLocal((current) => ({ ...current, [key]: value }));
    return (
        <AppShell title="Logs" appearance="flat">
            <div className="government-flat logs-page">
                <div className="page-hd">
                    <div>
                        <h1>Logs</h1>
                        <p className="page-sub">Investigate activity, failures and system events.</p>
                    </div>
                </div>
                <nav className="logs-tabs" aria-label="Log categories">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            aria-current={filters.tab === tab.id ? 'page' : undefined}
                            onClick={() => apply({ ...local, tab: tab.id, category: '', action: '', severity: '', outcome: '' })}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>
                <p className="page-sub">{help[filters.tab]}</p>
                <p className="page-sub">Times are shown in Uganda time (EAT, UTC+3). Sign-ins appear under User Activity; Laravel Errors records application errors.</p>
                <form
                    className="filters-bar logs-filters"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply();
                    }}
                >
                    <label>
                        Search
                        <input
                            className="input"
                            value={local.q}
                            placeholder="User, action or record ID"
                            onChange={(event) => field('q', event.target.value)}
                        />
                    </label>
                    <label>
                        User
                        <select value={local.actor} onChange={(event) => field('actor', event.target.value)}>
                            <option value="">All users</option>
                            {actors.map((actor) => (
                                <option key={actor.id} value={actor.id}>
                                    {actor.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Module
                        <select value={local.category} onChange={(event) => field('category', event.target.value)}>
                            <option value="">All modules</option>
                            {categories.map((category) => (
                                <option key={category}>{category}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Action/event
                        <select value={local.action} onChange={(event) => field('action', event.target.value)}>
                            <option value="">All actions</option>
                            {actions.map((action) => (
                                <option key={action}>{action}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Severity
                        <select value={local.severity} onChange={(event) => field('severity', event.target.value)}>
                            <option value="">All levels</option>
                            {['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'].map((level) => (
                                <option key={level}>{level}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        From
                        <input type="date" value={local.from} max={local.to || undefined} onChange={(event) => field('from', event.target.value)} />
                    </label>
                    <label>
                        To
                        <input type="date" value={local.to} min={local.from || undefined} onChange={(event) => field('to', event.target.value)} />
                    </label>
                    <button className="btn btn-primary" type="submit">
                        Apply filters
                    </button>
                    <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() =>
                            apply({ tab: filters.tab, q: '', actor: '', action: '', category: '', severity: '', outcome: '', from: '', to: '' })
                        }
                    >
                        Clear
                    </button>
                </form>
                <div className="card">
                    <div className="table-scroll">
                        <table className="tbl">
                            <thead>
                                <tr>
                                    <th>Date/time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Module / record</th>
                                    <th>Severity / result</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((log) => (
                                    <tr key={log.id}>
                                        <td>{log.timestamp}</td>
                                        <td>{log.actor}</td>
                                        <td className="log-action">{log.action}</td>
                                        <td>
                                            {typeof log.details.module === 'string' ? log.details.module : log.category}
                                            {log.resource && (
                                                <small>
                                                    {log.resource} {log.record_id ? `#${log.record_id}` : ''}
                                                </small>
                                            )}
                                        </td>
                                        <td>
                                            <span className={log.outcome === 'failure' ? 'log-failure' : ''}>
                                                {log.severity} · {log.outcome}
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" className="btn btn-ghost" onClick={() => setSelected(log)}>
                                                View details
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {!logs.data.length && <EmptyState>No log entries match these filters.</EmptyState>}
                    <Pagination meta={logs.meta} />
                </div>
                {selected && (
                    <Modal title="Log details" className="government-flat log-detail-modal" onClose={() => setSelected(null)}>
                        <dl className="mail-provenance-facts">
                            <div>
                                <dt>User</dt>
                                <dd>{selected.actor}</dd>
                            </div>
                            <div>
                                <dt>Date/time</dt>
                                <dd>{selected.timestamp}</dd>
                            </div>
                            <div>
                                <dt>Action</dt>
                                <dd>{selected.action}</dd>
                            </div>
                            <div>
                                <dt>Resource</dt>
                                <dd>
                                    {selected.resource || selected.category} {selected.record_id}
                                </dd>
                            </div>
                            <div>
                                <dt>IP address</dt>
                                <dd>{selected.ip_address || 'Unavailable'}</dd>
                            </div>
                            <div>
                                <dt>Result</dt>
                                <dd>
                                    {selected.severity} · {selected.outcome}
                                </dd>
                            </div>
                        </dl>
                        {Object.entries(selected.details).map(([key, value]) => (
                            <section key={key} className="log-detail-section">
                                <h3>{key.replaceAll('_', ' ')}</h3>
                                <pre>{typeof value === 'string' ? value : JSON.stringify(value, null, 2)}</pre>
                            </section>
                        ))}
                    </Modal>
                )}
            </div>
        </AppShell>
    );
}
