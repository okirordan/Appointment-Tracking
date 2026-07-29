import { router } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import Pagination from '@/components/ats/pagination';
import type { PaginatedData } from '@/types';

interface AuditRow {
    id: number;
    timestamp: string;
    actor: string;
    category: string;
    action: string;
    outcome: string;
    ip_address: string | null;
}

interface Filters {
    q: string;
    category: string;
    outcome: string;
    from: string;
    to: string;
}

interface Props {
    filters: Filters;
    categories: string[];
    logs: PaginatedData<AuditRow>;
}

const categoryBadge: Record<string, string> = {
    login: 'st-received',
    task: 'st-inprogress',
    user: 'st-assigned',
    department: 'st-pending',
    settings: 'st-awaitingreview',
    report: 'st-completed',
    security: 'pr-urgent',
};

export default function AuditLog({ filters, categories, logs }: Props) {
    const [local, setLocal] = useState(filters);

    const apply = (changes: Partial<Filters>) => {
        const next = { ...local, ...changes };
        setLocal(next);
        router.get(
            route('admin.audit.index'),
            Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '')),
            { preserveState: true },
        );
    };

    return (
        <AppShell title="Audit Log">
            <div className="page-hd">
                <div>
                    <h1>Audit Log</h1>
                    <div className="page-sub">Immutable record of significant system and business actions</div>
                </div>
            </div>
            <div className="filters-bar">
                <input
                    className="input"
                    style={{ width: 240 }}
                    type="text"
                    placeholder="Search action or user…"
                    aria-label="Search audit log"
                    value={local.q}
                    onChange={(event) => setLocal({ ...local, q: event.target.value })}
                    onKeyDown={(event) => event.key === 'Enter' && apply({})}
                    onBlur={() => local.q !== filters.q && apply({})}
                />
                <select className="select" aria-label="Category" value={local.category} onChange={(event) => apply({ category: event.target.value })}>
                    <option value="">All categories</option>
                    {categories.map((category) => (
                        <option key={category} value={category}>
                            {category}
                        </option>
                    ))}
                </select>
                <select className="select" aria-label="Outcome" value={local.outcome} onChange={(event) => apply({ outcome: event.target.value })}>
                    <option value="">All outcomes</option>
                    <option value="success">Success</option>
                    <option value="failure">Failure</option>
                </select>
                <input className="input" type="date" aria-label="From date" value={local.from} onChange={(event) => apply({ from: event.target.value })} />
                <input className="input" type="date" aria-label="To date" value={local.to} onChange={(event) => apply({ to: event.target.value })} />
                <button
                    type="button"
                    className="btn btn-ghost"
                    onClick={() => apply({ q: '', category: '', outcome: '', from: '', to: '' })}
                >
                    Clear
                </button>
            </div>
            <div className="card">
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Category</th>
                                <th>Action</th>
                                <th>Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((log) => (
                                <tr key={log.id}>
                                    <td className="ref" style={{ whiteSpace: 'nowrap' }}>
                                        {log.timestamp}
                                    </td>
                                    <td>{log.actor}</td>
                                    <td>
                                        <span className={`badge ${categoryBadge[log.category] ?? 'st-archived'}`}>{log.category}</span>
                                    </td>
                                    <td>{log.action}</td>
                                    <td>
                                        <span className={`badge ${log.outcome === 'success' ? 'st-completed' : 'pr-urgent'}`}>{log.outcome}</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {logs.data.length === 0 && <EmptyState>No audit entries match your filters</EmptyState>}
                <Pagination meta={logs.meta} />
            </div>
        </AppShell>
    );
}
