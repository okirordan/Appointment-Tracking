import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import ProgressBar from '@/components/ats/progress-bar';
import { StatCard } from '@/components/ats/stat-card';
import { Link, router } from '@inertiajs/react';
import { ArrowUpRight, ChevronDown, Download } from 'lucide-react';
import { Fragment, useState } from 'react';

interface Summary {
    total: number;
    completed: number;
    active: number;
    awaiting_review: number;
    overdue: number;
    completion_rate: number;
    overdue_rate: number;
    average_progress: number;
    on_time_rate: number | null;
}

interface OfficerBreakdown extends Summary {
    officer_id: number | null;
    officer: string;
    title: string | null;
}

interface DepartmentReport extends Summary {
    id: number | null;
    name: string;
    officers: OfficerBreakdown[];
}

interface Props {
    from: string;
    to: string;
    generatedAt: string;
    summary: Summary;
    correspondenceSummary: {
        total: number;
        incoming: number;
        outgoing: number;
        drafts: number;
        awaiting_action: number;
        completed_archived: number;
    };
    departments: DepartmentReport[];
}

export default function Reports({ from, to, generatedAt, summary, correspondenceSummary, departments }: Props) {
    const [range, setRange] = useState({ from, to });
    const [expanded, setExpanded] = useState<string | null>(null);

    const apply = () => {
        router.get(
            route('reports.index'),
            {
                ...(range.from !== '' ? { from: range.from } : {}),
                ...(range.to !== '' ? { to: range.to } : {}),
            },
            { preserveState: true },
        );
    };

    const clearRange = () => {
        setRange({ from: '', to: '' });
        router.get(route('reports.index'));
    };

    const exportUrl = () => {
        const params = new URLSearchParams();
        if (from !== '') params.set('from', from);
        if (to !== '') params.set('to', to);
        const suffix = params.toString();
        return route('reports.export') + (suffix === '' ? '' : `?${suffix}`);
    };

    const periodLabel = from === '' && to === '' ? 'All time' : `${from || 'start'} → ${to || 'today'}`;

    return (
        <AppShell title="Reports & Performance">
            <div className="page-hd">
                <div>
                    <h1>Reports &amp; Performance</h1>
                    <div className="page-sub">
                        Period: {periodLabel} · Generated {generatedAt}
                    </div>
                </div>
                <a href={exportUrl()} className="btn btn-ghost">
                    <Download aria-hidden="true" />
                    Export CSV
                </a>
            </div>

            <div className="card report-filter-card">
                <div className="report-filter-copy">
                    <h3>Reporting period</h3>
                    <p>Filter every metric and breakdown by assignment creation date.</p>
                </div>
                <div className="report-filter-fields">
                    <div className="field report-date-field">
                        <label htmlFor="rp-from">From</label>
                        <input
                            id="rp-from"
                            className="input"
                            type="date"
                            value={range.from}
                            max={range.to || undefined}
                            onChange={(event) => setRange({ ...range, from: event.target.value })}
                        />
                    </div>
                    <div className="field report-date-field">
                        <label htmlFor="rp-to">To</label>
                        <input
                            id="rp-to"
                            className="input"
                            type="date"
                            value={range.to}
                            min={range.from || undefined}
                            onChange={(event) => setRange({ ...range, to: event.target.value })}
                        />
                    </div>
                    <button type="button" className="btn btn-primary report-filter-action" onClick={apply}>
                        Apply period
                    </button>
                    {(from !== '' || to !== '') && (
                        <button type="button" className="btn btn-ghost report-filter-action" onClick={clearRange}>
                            Clear
                        </button>
                    )}
                </div>
            </div>

            <div className="report-stat-grid">
                <StatCard label="Total Assignments" value={summary.total} />
                <StatCard label="Active Work" value={summary.active} />
                <StatCard label="Completed" value={summary.completed} />
                <StatCard label="Completion Rate" value={`${summary.completion_rate}%`} />
                <StatCard label="Average Progress" value={`${summary.average_progress}%`} />
                <StatCard label="On-time Delivery" value={summary.on_time_rate === null ? '—' : `${summary.on_time_rate}%`} />
            </div>

            {correspondenceSummary.total > 0 && (
                <>
                    <div className="section-title">Office correspondence</div>
                    <div className="report-stat-grid">
                        <StatCard label="Total Correspondence" value={correspondenceSummary.total} />
                        <StatCard label="Incoming" value={correspondenceSummary.incoming} />
                        <StatCard label="Outgoing" value={correspondenceSummary.outgoing} />
                        <StatCard label="Drafts" value={correspondenceSummary.drafts} />
                        <StatCard label="Awaiting Action" value={correspondenceSummary.awaiting_action} />
                        <StatCard label="Completed / Archived" value={correspondenceSummary.completed_archived} />
                    </div>
                </>
            )}

            <div className="card report-department-card">
                <div className="card-hd">
                    <div>
                        <h3>Department performance</h3>
                        <div className="page-sub">Expand a department, then select a staff member to view their full performance record.</div>
                    </div>
                </div>
                <div className="table-scroll">
                    <table className="tbl report-department-table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Assignments</th>
                                <th>Completion</th>
                                <th>Avg Progress</th>
                                <th>Overdue</th>
                                <th>On-time</th>
                            </tr>
                        </thead>
                        <tbody>
                            {departments.map((department) => (
                                <Fragment key={department.name}>
                                    <tr
                                        className="row report-department-row"
                                        onClick={() => setExpanded(expanded === department.name ? null : department.name)}
                                    >
                                        <td>
                                            <button type="button" className="report-department-toggle" aria-expanded={expanded === department.name}>
                                                <ChevronDown aria-hidden="true" />
                                                <span>{department.name}</span>
                                                <small>{department.officers.length} staff</small>
                                            </button>
                                        </td>
                                        <td>{department.total}</td>
                                        <td className="report-metric-cell">
                                            <ProgressBar percent={department.completion_rate} variant="done" />
                                            <span>{department.completion_rate}%</span>
                                        </td>
                                        <td className="report-metric-cell">
                                            <ProgressBar percent={department.average_progress} />
                                            <span>{department.average_progress}%</span>
                                        </td>
                                        <td className={department.overdue > 0 ? 'report-overdue-value' : ''}>{department.overdue}</td>
                                        <td>{department.on_time_rate === null ? '—' : `${department.on_time_rate}%`}</td>
                                    </tr>
                                    {expanded === department.name &&
                                        department.officers.map((officer) => (
                                            <tr className="report-officer-row" key={`${department.name}-${officer.officer_id ?? officer.officer}`}>
                                                <td>
                                                    {officer.officer_id === null ? (
                                                        <div className="report-officer-copy">
                                                            <strong>{officer.officer}</strong>
                                                            <span>{officer.title ?? 'Staff member'}</span>
                                                        </div>
                                                    ) : (
                                                        <Link className="report-officer-link" href={route('performance.show', officer.officer_id)}>
                                                            <span className="report-officer-copy">
                                                                <strong>{officer.officer}</strong>
                                                                <span>{officer.title ?? 'Staff member'}</span>
                                                            </span>
                                                            <ArrowUpRight aria-hidden="true" />
                                                        </Link>
                                                    )}
                                                </td>
                                                <td>{officer.total}</td>
                                                <td className="report-metric-cell">
                                                    <ProgressBar percent={officer.completion_rate} variant="done" />
                                                    <span>{officer.completion_rate}%</span>
                                                </td>
                                                <td className="report-metric-cell">
                                                    <ProgressBar percent={officer.average_progress} />
                                                    <span>{officer.average_progress}%</span>
                                                </td>
                                                <td className={officer.overdue > 0 ? 'report-overdue-value' : ''}>{officer.overdue}</td>
                                                <td>{officer.on_time_rate === null ? '—' : `${officer.on_time_rate}%`}</td>
                                            </tr>
                                        ))}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
                {departments.length === 0 && <EmptyState>No assignments in the selected period</EmptyState>}
            </div>
        </AppShell>
    );
}
