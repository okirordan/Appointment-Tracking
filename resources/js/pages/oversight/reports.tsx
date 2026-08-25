import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import ProgressBar from '@/components/ats/progress-bar';
import { StatCard } from '@/components/ats/stat-card';
import { ArrowUpRight, ChevronDown, Download } from '@/components/icons';
import { Link, router } from '@inertiajs/react';
import { FormEvent, Fragment, useMemo, useState } from 'react';

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

interface Filters {
    from: string;
    to: string;
    department: string;
    officer: string;
    status: string;
    priority: string;
    timeliness: string;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    filters: Filters;
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
    departmentOptions: { id: number; name: string; active: boolean }[];
    officerOptions: { id: number; name: string; department_id: number | null; department_name: string; active: boolean }[];
    statusOptions: Option[];
    priorityOptions: Option[];
    statusBreakdown: { label: string; badge_class: string; count: number; percentage: number }[];
    priorityBreakdown: { label: string; badge_class: string; count: number; percentage: number }[];
    workflowSummary: {
        created_by_me: number;
        assigned_to_me: number;
        awaiting_my_review: number;
        returned_for_correction: number;
        direct_assignments: number;
        average_route_levels: number;
    };
}

const cleanFilters = (filters: Filters) => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

const formatInputDate = (date: Date) => {
    const offset = date.getTimezoneOffset();
    return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 10);
};

export default function Reports({
    filters,
    generatedAt,
    summary,
    correspondenceSummary,
    departments,
    departmentOptions,
    officerOptions,
    statusOptions,
    priorityOptions,
    statusBreakdown,
    priorityBreakdown,
    workflowSummary,
}: Props) {
    const [filterState, setFilterState] = useState(filters);
    const [expanded, setExpanded] = useState<string | null>(null);

    const availableOfficers = useMemo(
        () => officerOptions.filter((officer) => filterState.department === '' || String(officer.department_id) === filterState.department),
        [filterState.department, officerOptions],
    );

    const apply = (event?: FormEvent) => {
        event?.preventDefault();
        router.get(route('reports.index'), cleanFilters(filterState), { preserveState: true, preserveScroll: true });
    };

    const applyPreset = (days: number | null) => {
        const next = { ...filterState };
        if (days === null) {
            next.from = '';
            next.to = '';
        } else {
            const to = new Date();
            const from = new Date();
            from.setDate(from.getDate() - (days - 1));
            next.from = formatInputDate(from);
            next.to = formatInputDate(to);
        }
        setFilterState(next);
        router.get(route('reports.index'), cleanFilters(next), { preserveState: true, preserveScroll: true });
    };

    const clearFilters = () => {
        const cleared = { from: '', to: '', department: '', officer: '', status: '', priority: '', timeliness: '' };
        setFilterState(cleared);
        router.get(route('reports.index'));
    };

    const exportUrl = () => {
        const suffix = new URLSearchParams(cleanFilters(filters)).toString();
        return route('reports.export') + (suffix === '' ? '' : `?${suffix}`);
    };

    const activeParameterCount = Object.values(filters).filter(Boolean).length;
    const periodLabel = filters.from === '' && filters.to === '' ? 'All time' : `${filters.from || 'Earliest'} to ${filters.to || 'Today'}`;
    const selectedDepartment = departmentOptions.find((option) => String(option.id) === filters.department)?.name;
    const selectedOfficer = officerOptions.find((option) => String(option.id) === filters.officer)?.name;

    return (
        <AppShell title="Reports & Performance">
            <div className="page-hd">
                <div>
                    <h1>Reports &amp; Performance</h1>
                    <div className="page-sub">Generated {generatedAt} from the parameters below</div>
                </div>
                <a href={exportUrl()} className="btn btn-ghost">
                    <Download aria-hidden="true" />
                    Export {summary.total} rows
                </a>
            </div>

            <form className="card report-filter-card report-parameter-card" onSubmit={apply}>
                <div className="report-filter-copy">
                    <span className="result-eyebrow">Report parameters</span>
                    <h3>Generate an assignment report</h3>
                    <p>Every selection updates the headline metrics, department detail, correspondence totals, and CSV export.</p>
                    <div className="report-preset-row" aria-label="Quick reporting periods">
                        <button type="button" className="report-preset" onClick={() => applyPreset(7)}>
                            Last 7 days
                        </button>
                        <button type="button" className="report-preset" onClick={() => applyPreset(30)}>
                            Last 30 days
                        </button>
                        <button type="button" className="report-preset" onClick={() => applyPreset(90)}>
                            Last 90 days
                        </button>
                        <button type="button" className="report-preset" onClick={() => applyPreset(null)}>
                            All time
                        </button>
                    </div>
                </div>
                <div className="report-parameter-grid">
                    <div className="field">
                        <label htmlFor="rp-from">Created from</label>
                        <input
                            id="rp-from"
                            className="input"
                            type="date"
                            value={filterState.from}
                            max={filterState.to || undefined}
                            onChange={(event) => setFilterState({ ...filterState, from: event.target.value })}
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="rp-to">Created to</label>
                        <input
                            id="rp-to"
                            className="input"
                            type="date"
                            value={filterState.to}
                            min={filterState.from || undefined}
                            onChange={(event) => setFilterState({ ...filterState, to: event.target.value })}
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="rp-department">Department</label>
                        <select
                            id="rp-department"
                            value={filterState.department}
                            onChange={(event) => setFilterState({ ...filterState, department: event.target.value, officer: '' })}
                        >
                            <option value="">All visible departments</option>
                            {departmentOptions.map((department) => (
                                <option key={department.id} value={department.id}>
                                    {department.name}
                                    {department.active ? '' : ' (inactive)'}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="field">
                        <label htmlFor="rp-officer">Assigned officer</label>
                        <select
                            id="rp-officer"
                            value={filterState.officer}
                            onChange={(event) => setFilterState({ ...filterState, officer: event.target.value })}
                        >
                            <option value="">All visible officers</option>
                            {availableOfficers.map((officer) => (
                                <option key={officer.id} value={officer.id}>
                                    {filterState.department === '' ? `${officer.department_name} — ` : ''}
                                    {officer.name}
                                    {officer.active ? '' : ' (inactive)'}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="field">
                        <label htmlFor="rp-status">Workflow status</label>
                        <select
                            id="rp-status"
                            value={filterState.status}
                            onChange={(event) => setFilterState({ ...filterState, status: event.target.value })}
                        >
                            <option value="">All statuses</option>
                            {statusOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="field">
                        <label htmlFor="rp-priority">Priority</label>
                        <select
                            id="rp-priority"
                            value={filterState.priority}
                            onChange={(event) => setFilterState({ ...filterState, priority: event.target.value })}
                        >
                            <option value="">All priorities</option>
                            {priorityOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="field">
                        <label htmlFor="rp-timeliness">Deadline condition</label>
                        <select
                            id="rp-timeliness"
                            value={filterState.timeliness}
                            onChange={(event) => setFilterState({ ...filterState, timeliness: event.target.value })}
                        >
                            <option value="">All deadlines</option>
                            <option value="overdue">Overdue only</option>
                            <option value="due_soon">Due in the next 7 days</option>
                            <option value="no_due_date">No due date</option>
                        </select>
                    </div>
                    <div className="report-filter-actions">
                        <button type="submit" className="btn btn-primary">
                            Generate report
                        </button>
                        {activeParameterCount > 0 && (
                            <button type="button" className="btn btn-ghost" onClick={clearFilters}>
                                Reset
                            </button>
                        )}
                    </div>
                </div>
            </form>

            <div className="report-scope-strip" aria-label="Generated report scope">
                <strong>{summary.total} assignments</strong>
                <span>{periodLabel}</span>
                {selectedDepartment && <span>{selectedDepartment}</span>}
                {selectedOfficer && <span>{selectedOfficer}</span>}
                {filters.status && <span>{statusOptions.find((option) => option.value === filters.status)?.label}</span>}
                {filters.priority && <span>{priorityOptions.find((option) => option.value === filters.priority)?.label} priority</span>}
                {filters.timeliness && <span>{filters.timeliness.replaceAll('_', ' ')}</span>}
            </div>

            <div className="report-stat-grid">
                <StatCard label="Total Assignments" value={summary.total} />
                <StatCard label="Active Work" value={summary.active} />
                <StatCard label="Completed" value={summary.completed} />
                <StatCard label="Completion Rate" value={`${summary.completion_rate}%`} />
                <StatCard label="Average Progress" value={`${summary.average_progress}%`} />
                <StatCard label="On-time Delivery" value={summary.on_time_rate === null ? '—' : `${summary.on_time_rate}%`} />
            </div>

            <div className="report-insight-grid">
                <section className="card report-breakdown-card">
                    <div className="card-hd">
                        <div>
                            <span className="result-eyebrow">Delivery mix</span>
                            <h3>Status breakdown</h3>
                        </div>
                    </div>
                    {statusBreakdown.map((item) => (
                        <div className="report-breakdown-row" key={item.label}>
                            <div>
                                <span className={`badge ${item.badge_class}`}>{item.label}</span>
                                <strong>{item.count}</strong>
                            </div>
                            <ProgressBar percent={item.percentage} />
                        </div>
                    ))}
                    {statusBreakdown.length === 0 && <EmptyState>No assignments match these parameters.</EmptyState>}
                </section>
                <section className="card report-breakdown-card">
                    <div className="card-hd">
                        <div>
                            <span className="result-eyebrow">Workload mix</span>
                            <h3>Priority breakdown</h3>
                        </div>
                    </div>
                    {priorityBreakdown.map((item) => (
                        <div className="report-breakdown-row" key={item.label}>
                            <div>
                                <span className={`badge ${item.badge_class}`}>{item.label}</span>
                                <strong>{item.count}</strong>
                            </div>
                            <ProgressBar percent={item.percentage} />
                        </div>
                    ))}
                    {priorityBreakdown.length === 0 && <EmptyState>No priority data in this report.</EmptyState>}
                </section>
                <section className="card report-workflow-card">
                    <div className="card-hd">
                        <div>
                            <span className="result-eyebrow">Workflow control</span>
                            <h3>Routing signals</h3>
                        </div>
                    </div>
                    <dl>
                        <div>
                            <dt>Awaiting my review</dt>
                            <dd>{workflowSummary.awaiting_my_review}</dd>
                        </div>
                        <div>
                            <dt>Returned for correction</dt>
                            <dd>{workflowSummary.returned_for_correction}</dd>
                        </div>
                        <div>
                            <dt>Direct assignments</dt>
                            <dd>{workflowSummary.direct_assignments}</dd>
                        </div>
                        <div>
                            <dt>Average route levels</dt>
                            <dd>{workflowSummary.average_route_levels}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            {correspondenceSummary.total > 0 && (
                <>
                    <div className="section-title">Correspondence connected to this report</div>
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
                        <span className="result-eyebrow">Generated detail</span>
                        <h3>Department performance</h3>
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
                {departments.length === 0 && <EmptyState>No assignments match the selected report parameters.</EmptyState>}
            </div>
        </AppShell>
    );
}
