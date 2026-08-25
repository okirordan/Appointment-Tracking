import AppShell from '@/components/ats/app-shell';
import { OverdueTag, StatusBadge } from '@/components/ats/badges';
import EmptyState from '@/components/ats/empty-state';
import ProgressBar from '@/components/ats/progress-bar';
import { StatCard, StatGrid } from '@/components/ats/stat-card';
import { ArrowRight, Mail, Plus } from '@/components/icons';
import type { TaskRow } from '@/types';
import { Link, router } from '@inertiajs/react';

interface Props {
    stats: { total: number; completed: number; overdue: number; active: number };
    overdue: TaskRow[];
    recent: TaskRow[];
    status_breakdown: { label: string; count: number; pct: number }[];
    departmentName: string;
    canCreate: boolean;
}

export default function DepartmentDashboard({ stats, overdue, recent, status_breakdown, departmentName, canCreate }: Props) {
    return (
        <AppShell title="Department Work">
            <div className="page-hd">
                <div>
                    <h1>Department Work</h1>
                    <div className="page-sub">{departmentName}</div>
                </div>
                {canCreate && (
                    <button type="button" className="btn btn-primary" onClick={() => router.get(route('tasks.index'))}>
                        <Plus aria-hidden="true" />
                        New Task
                    </button>
                )}
            </div>
            <StatGrid>
                <StatCard label="Total Tasks" value={stats.total} />
                <StatCard label="Completed" value={stats.completed} />
                <StatCard label="Overdue" value={stats.overdue} warn />
                <StatCard label="Active" value={stats.active} />
            </StatGrid>
            <section className="card department-correspondence-workspace" aria-labelledby="department-correspondence-title">
                <span className="department-correspondence-icon" aria-hidden="true">
                    <Mail />
                </span>
                <div>
                    <span className="department-correspondence-kicker">Department correspondence</span>
                    <h2 id="department-correspondence-title">Correspondence workspace</h2>
                    <p>Review action-required mail, copied correspondence, sent records, responses, and closed items from one workspace.</p>
                </div>
                <Link className="btn btn-primary" href={route('correspondence.index')}>
                    Open correspondence <ArrowRight aria-hidden="true" />
                </Link>
            </section>
            <div className="grid2">
                <div className="card">
                    <div className="card-hd">
                        <h3>Overdue &amp; Stale Tasks</h3>
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
                                </tr>
                            </thead>
                            <tbody>
                                {overdue.map((task) => (
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
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {overdue.length === 0 && <EmptyState>No overdue tasks</EmptyState>}

                    <div className="card-hd" style={{ marginTop: 22 }}>
                        <h3>Recent Tasks</h3>
                    </div>
                    {recent.map((task) => (
                        <div
                            key={task.id}
                            className="list-row"
                            style={{ cursor: 'pointer' }}
                            onClick={() => router.get(route('tasks.show', task.id))}
                        >
                            <div style={{ flex: 1 }}>
                                <div style={{ fontWeight: 600, fontSize: 13 }}>{task.title}</div>
                                <div className="ref">
                                    {task.reference} · {task.assigned_to_name}
                                </div>
                            </div>
                            <StatusBadge label={task.status} badgeClass={task.status_class} />
                        </div>
                    ))}
                    {recent.length === 0 && <EmptyState>No tasks yet</EmptyState>}
                </div>
                <div className="card">
                    <h3>Status Breakdown</h3>
                    <div style={{ marginTop: 14 }}>
                        {status_breakdown.map((status) => (
                            <div key={status.label} style={{ marginBottom: 12 }}>
                                <div
                                    style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, fontWeight: 600, color: 'var(--title)' }}
                                >
                                    <span>{status.label}</span>
                                    <span style={{ color: 'var(--label)', fontWeight: 500 }}>{status.count}</span>
                                </div>
                                <div style={{ marginTop: 6 }}>
                                    <ProgressBar percent={status.pct} />
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
