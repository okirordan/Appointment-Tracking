import AppShell from '@/components/ats/app-shell';
import { OverdueTag, StatusBadge } from '@/components/ats/badges';
import EmptyState from '@/components/ats/empty-state';
import ProgressBar from '@/components/ats/progress-bar';
import type { TaskRow } from '@/types';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface PerformanceRow {
    id: number;
    full_name: string;
    title: string | null;
    department_id: number | null;
    department_name: string;
    division_id: number | null;
    division_name: string;
    assigned: number;
    completed: number;
    in_progress: number;
    overdue: number;
    average_progress: number;
    on_time_rate: number | null;
}

interface PortfolioTask extends TaskRow {
    assigned_label: string;
    completed_label: string;
}

interface SelectedOfficer extends PerformanceRow {
    initials: string;
    tasks: PortfolioTask[];
    status_distribution: { label: string; count: number; pct: number }[];
}

interface Props {
    filters: { q: string; department: string; division: string };
    departmentOptions: { id: number; name: string; active: boolean }[];
    divisionOptions: { id: number; department_id: number; name: string; department_name: string; active: boolean }[];
    rows: PerformanceRow[];
    departmentSummaries: Record<string, { assigned: number; completed: number; completion_rate: number }>;
    selected: SelectedOfficer | null;
}

function PerformanceRows({ rows, onOpen }: { rows: PerformanceRow[]; onOpen: (id: number) => void }) {
    return (
        <div className="table-scroll">
            <table className="tbl officer-performance-table">
                <thead>
                    <tr>
                        <th>Officer</th>
                        <th>Position</th>
                        <th>Assigned</th>
                        <th>Completed</th>
                        <th>In Progress</th>
                        <th>Overdue</th>
                        <th>Avg Progress</th>
                        <th>On-time Rate</th>
                        <th aria-label="Actions" />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.id}
                            className="row"
                            tabIndex={0}
                            onClick={() => onOpen(row.id)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    onOpen(row.id);
                                }
                            }}
                        >
                            <td className="officer-performance-name">{row.full_name}</td>
                            <td>{row.title ?? '—'}</td>
                            <td>{row.assigned}</td>
                            <td>{row.completed}</td>
                            <td>{row.in_progress}</td>
                            <td>{row.overdue > 0 ? <OverdueTag>{row.overdue}</OverdueTag> : 0}</td>
                            <td className="officer-performance-progress">
                                <div>
                                    <ProgressBar percent={row.average_progress} />
                                </div>
                                <span>{row.average_progress}%</span>
                            </td>
                            <td>{row.on_time_rate === null ? '—' : `${row.on_time_rate}%`}</td>
                            <td>
                                <button
                                    type="button"
                                    className="btn btn-ghost officer-performance-detail"
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        onOpen(row.id);
                                    }}
                                >
                                    Detail
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function OfficerPerformance({ filters, departmentOptions, divisionOptions, rows, departmentSummaries, selected }: Props) {
    const [filterState, setFilterState] = useState(filters);

    const availableDivisions = divisionOptions.filter(
        (division) => filterState.department === '' || String(division.department_id) === filterState.department,
    );

    const applyFilters = () => {
        router.get(route('performance.index'), Object.fromEntries(Object.entries(filterState).filter(([, value]) => value.trim() !== '')), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        const cleared = { q: '', department: '', division: '' };
        setFilterState(cleared);
        router.get(route('performance.index'), {}, { preserveState: true });
    };

    const groups = useMemo(() => {
        const departments = new Map<string, { name: string; divisions: Map<string, { name: string; rows: PerformanceRow[] }> }>();
        rows.forEach((row) => {
            const departmentKey = row.department_id === null ? 'central' : String(row.department_id);
            const divisionKey = row.division_id === null ? 'unassigned' : String(row.division_id);
            if (!departments.has(departmentKey)) departments.set(departmentKey, { name: row.department_name, divisions: new Map() });
            const department = departments.get(departmentKey)!;
            if (!department.divisions.has(divisionKey)) department.divisions.set(divisionKey, { name: row.division_name, rows: [] });
            department.divisions.get(divisionKey)!.rows.push(row);
        });
        return Array.from(departments, ([key, department]) => {
            const departmentRows = Array.from(department.divisions.values()).flatMap((division) => division.rows);
            const fallbackAssigned = departmentRows.reduce((total, row) => total + row.assigned, 0);
            const fallbackCompleted = departmentRows.reduce((total, row) => total + row.completed, 0);
            const summary = departmentSummaries[key] ?? {
                assigned: fallbackAssigned,
                completed: fallbackCompleted,
                completion_rate: fallbackAssigned === 0 ? 0 : Math.round((fallbackCompleted / fallbackAssigned) * 100),
            };

            return {
                key,
                name: department.name,
                count: departmentRows.length,
                completed: summary.completed,
                assigned: summary.assigned,
                completionRate: summary.completion_rate,
                divisions: Array.from(department.divisions, ([divisionKey, division]) => ({ key: divisionKey, ...division })),
            };
        });
    }, [departmentSummaries, rows]);

    const openOfficer = (id: number) => {
        router.get(route('performance.show', id), Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')));
    };

    return (
        <AppShell title="Performance Monitor">
            <div className="page-hd">
                <div>
                    <h1>Performance Monitor</h1>
                    <div className="page-sub">
                        {selected === null ? 'Assignment delivery metrics per officer' : `${selected.full_name} — detailed performance`}
                    </div>
                </div>
                {selected !== null && (
                    <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() =>
                            router.get(route('performance.index'), Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')))
                        }
                    >
                        Back to all officers
                    </button>
                )}
            </div>

            {selected === null && (
                <>
                    <div className="filters-bar">
                        <div className="field officer-filter-search">
                            <label htmlFor="performance-search">Staff name or position</label>
                            <input
                                id="performance-search"
                                type="search"
                                placeholder="Search staff…"
                                value={filterState.q}
                                onChange={(event) => setFilterState({ ...filterState, q: event.target.value })}
                                onKeyDown={(event) => event.key === 'Enter' && applyFilters()}
                            />
                        </div>
                        <div className="field officer-filter-select">
                            <label htmlFor="performance-department">Department</label>
                            <select
                                id="performance-department"
                                value={filterState.department}
                                onChange={(event) => setFilterState({ ...filterState, department: event.target.value, division: '' })}
                            >
                                <option value="">All departments</option>
                                {departmentOptions.map((department) => (
                                    <option key={department.id} value={department.id}>
                                        {department.name}
                                        {department.active ? '' : ' (inactive)'}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="field officer-filter-select">
                            <label htmlFor="performance-division">Division</label>
                            <select
                                id="performance-division"
                                value={filterState.division}
                                onChange={(event) => setFilterState({ ...filterState, division: event.target.value })}
                            >
                                <option value="">All divisions</option>
                                {availableDivisions.map((division) => (
                                    <option key={division.id} value={division.id}>
                                        {filterState.department === '' ? `${division.department_name} — ` : ''}
                                        {division.name}
                                        {division.active ? '' : ' (inactive)'}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <button type="button" className="btn btn-primary officer-filter-action" onClick={applyFilters}>
                            Apply filters
                        </button>
                        {(filters.q !== '' || filters.department !== '' || filters.division !== '') && (
                            <button type="button" className="btn btn-ghost officer-filter-action" onClick={clearFilters}>
                                Clear
                            </button>
                        )}
                    </div>
                    <div className="officer-performance-groups">
                        {groups.map((department) => (
                            <section className="card officer-performance-department" key={department.key}>
                                <div className="officer-performance-heading">
                                    <div>
                                        <span className="result-eyebrow">Department</span>
                                        <h2>{department.name}</h2>
                                        <div className="officer-department-summary" aria-label={`${department.name} completion summary`}>
                                            <div className="officer-department-summary-copy">
                                                <span>Completion rate</span>
                                                <strong>{department.completionRate}%</strong>
                                            </div>
                                            <ProgressBar percent={department.completionRate} variant="done" />
                                            <p>
                                                {department.completed} of {department.assigned} assignments completed
                                            </p>
                                        </div>
                                    </div>
                                    <span className="badge">{department.count} staff</span>
                                </div>
                                {department.divisions.map((division) => (
                                    <section className="officer-performance-division" key={division.key}>
                                        <div className="officer-performance-division-title">
                                            <h3>{division.name}</h3>
                                            <span>{division.rows.length} staff</span>
                                        </div>
                                        <PerformanceRows rows={division.rows} onOpen={openOfficer} />
                                    </section>
                                ))}
                            </section>
                        ))}
                        {rows.length === 0 && (
                            <div className="card">
                                <EmptyState>No staff with assignments match these filters.</EmptyState>
                            </div>
                        )}
                    </div>
                </>
            )}

            {selected !== null && (
                <>
                    <div className="stat-grid">
                        <div className="stat-card">
                            <div className="stat-label">Assigned</div>
                            <div className="stat-value">{selected.assigned}</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-label">Completed</div>
                            <div className="stat-value">{selected.completed}</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-label">Overdue</div>
                            <div className="stat-value warn">{selected.overdue}</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-label">On-time Rate</div>
                            <div className="stat-value">{selected.on_time_rate === null ? '—' : `${selected.on_time_rate}%`}</div>
                        </div>
                    </div>
                    <div className="grid2">
                        <div className="card">
                            <div className="card-hd">
                                <h3>All Tasks</h3>
                            </div>
                            <div style={{ overflowX: 'auto' }}>
                                <table className="tbl">
                                    <thead>
                                        <tr>
                                            <th>Reference</th>
                                            <th>Title</th>
                                            <th>Assigned</th>
                                            <th>Due</th>
                                            <th>Completed</th>
                                            <th>Status</th>
                                            <th>Progress</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {selected.tasks.map((task) => (
                                            <tr key={task.id} className="row" onClick={() => router.get(route('tasks.show', task.id))}>
                                                <td className="ref">{task.reference}</td>
                                                <td>{task.title}</td>
                                                <td>{task.assigned_label}</td>
                                                <td>
                                                    {task.due_label}
                                                    {task.overdue && <OverdueTag> · overdue</OverdueTag>}
                                                </td>
                                                <td>{task.completed_label}</td>
                                                <td>
                                                    <StatusBadge label={task.status} badgeClass={task.status_class} />
                                                </td>
                                                <td>{task.progress}%</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {selected.tasks.length === 0 && <EmptyState>No assignments</EmptyState>}
                        </div>
                        <div className="card">
                            <h3>Status Distribution</h3>
                            <div style={{ marginTop: 14 }}>
                                {selected.status_distribution.map((status) => (
                                    <div key={status.label} style={{ marginBottom: 12 }}>
                                        <div
                                            style={{
                                                display: 'flex',
                                                justifyContent: 'space-between',
                                                fontSize: 12.5,
                                                fontWeight: 600,
                                                color: 'var(--title)',
                                            }}
                                        >
                                            <span>{status.label}</span>
                                            <span style={{ color: 'var(--label)', fontWeight: 500 }}>{status.count}</span>
                                        </div>
                                        <div style={{ marginTop: 6 }}>
                                            <ProgressBar percent={status.pct} />
                                        </div>
                                    </div>
                                ))}
                                {selected.status_distribution.length === 0 && <EmptyState>No data</EmptyState>}
                            </div>
                        </div>
                    </div>
                </>
            )}
        </AppShell>
    );
}
