import AppShell from '@/components/ats/app-shell';
import { OverdueTag, StatusBadge } from '@/components/ats/badges';
import EmptyState from '@/components/ats/empty-state';
import ProgressBar from '@/components/ats/progress-bar';
import { StatCard, StatGrid } from '@/components/ats/stat-card';
import { ArrowRight, Plus } from '@/components/icons';
import type { TaskRow } from '@/types';
import { Link, router } from '@inertiajs/react';

interface Props {
    stats: { total: number; completed: number; overdue: number; active: number; awaiting_review: number };
    stale: TaskRow[];
    department_performance: { id: number; name: string; completion_label: string; rate: number }[];
    canCreate: boolean;
    canDrillDownDepartmentPerformance: boolean;
}

export default function ExecutiveDashboard({ stats, stale, department_performance, canCreate, canDrillDownDepartmentPerformance }: Props) {
    return (
        <AppShell title="Executive Dashboard">
            <div className="page-hd">
                <div>
                    <h1>Executive Dashboard</h1>
                </div>
                {canCreate && (
                    <button type="button" className="btn btn-primary" onClick={() => router.get(route('tasks.index'))}>
                        <Plus aria-hidden="true" />
                        New Assignment
                    </button>
                )}
            </div>
            <StatGrid>
                <StatCard label="Total Assignments" value={stats.total} />
                <StatCard label="Completed" value={stats.completed} />
                <StatCard label="Overdue" value={stats.overdue} warn />
                <StatCard label="Awaiting Review" value={stats.awaiting_review} />
            </StatGrid>
            <div className="executive-dashboard-stack">
                <div className="card">
                    <div className="card-hd">
                        <h3>Stale Assignments</h3>
                    </div>
                    <div style={{ overflowX: 'auto' }}>
                        <table className="tbl">
                            <thead>
                                <tr>
                                    <th>Overdue</th>
                                    <th>Reference</th>
                                    <th>Title</th>
                                    <th>Assignee</th>
                                    <th>Status</th>
                                    <th>Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                {stale.map((task) => (
                                    <tr key={task.id} className="row" onClick={() => router.get(route('tasks.show', task.id))}>
                                        <td>
                                            <OverdueTag>{task.days_overdue_label}</OverdueTag>
                                        </td>
                                        <td className="ref">{task.reference}</td>
                                        <td>{task.title}</td>
                                        <td>{task.assigned_to_name}</td>
                                        <td>
                                            <StatusBadge label={task.status} badgeClass={task.status_class} />
                                        </td>
                                        <td>{task.progress}%</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {stale.length === 0 && <EmptyState>No stale assignments</EmptyState>}
                </div>
                <div className="card">
                    <div className="card-hd">
                        <div>
                            <h3>Performance by Department</h3>
                        </div>
                        {canDrillDownDepartmentPerformance && (
                            <Link className="btn btn-ghost" href={route('performance.index')}>
                                View all
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        )}
                    </div>
                    <div style={{ marginTop: 14 }}>
                        {department_performance.map((department) => (
                            <div key={department.id} className="department-performance-row">
                                <div
                                    style={{
                                        display: 'flex',
                                        justifyContent: 'space-between',
                                        fontSize: 13,
                                        fontWeight: 600,
                                        color: 'var(--title)',
                                    }}
                                >
                                    {canDrillDownDepartmentPerformance ? (
                                        <Link href={route('performance.index', { department: department.id })}>{department.name}</Link>
                                    ) : (
                                        <span>{department.name}</span>
                                    )}
                                    <span style={{ color: 'var(--label)', fontWeight: 500 }}>{department.completion_label}</span>
                                </div>
                                <div style={{ marginTop: 6 }}>
                                    <ProgressBar percent={department.rate} variant="done" />
                                </div>
                                {canDrillDownDepartmentPerformance && (
                                    <Link className="department-performance-link" href={route('performance.index', { department: department.id })}>
                                        View detailed performance <ArrowRight aria-hidden="true" />
                                    </Link>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
