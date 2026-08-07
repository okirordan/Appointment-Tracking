import AppShell from '@/components/ats/app-shell';
import { StatusBadge } from '@/components/ats/badges';
import EmptyState from '@/components/ats/empty-state';
import { StatCard, StatGrid } from '@/components/ats/stat-card';
import type { SharedData, TaskRow } from '@/types';
import { router, usePage } from '@inertiajs/react';

interface Props {
    stats: { total: number; completed: number; overdue: number; active: number };
    upcoming: TaskRow[];
}

export default function OfficerDashboard({ stats, upcoming }: Props) {
    const { auth } = usePage<SharedData>().props;
    const user = auth.user!;

    return (
        <AppShell title="My Dashboard">
            <h1>My Dashboard</h1>
            <div className="page-sub">
                {user.title ?? user.role_label} · {user.department?.name ?? 'Central / Office of the PS'}
            </div>
            <div style={{ marginTop: 20 }}>
                <StatGrid>
                    <StatCard label="Assigned to Me" value={stats.total} />
                    <StatCard label="Active" value={stats.active} />
                    <StatCard label="Completed" value={stats.completed} />
                    <StatCard label="Overdue" value={stats.overdue} warn />
                </StatGrid>
            </div>
            <div className="card">
                <div className="card-hd">
                    <h3>Upcoming Deadlines (7 days)</h3>
                </div>
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Due</th>
                                <th>Reference</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {upcoming.map((task) => (
                                <tr key={task.id} className="row">
                                    <td>{task.due_label}</td>
                                    <td className="ref">{task.reference}</td>
                                    <td style={{ cursor: 'pointer' }} onClick={() => router.get(route('tasks.show', task.id))}>
                                        {task.title}
                                    </td>
                                    <td>
                                        <StatusBadge label={task.status} badgeClass={task.status_class} />
                                    </td>
                                    <td>{task.progress}%</td>
                                    <td>
                                        <button
                                            type="button"
                                            className="btn btn-ghost"
                                            style={{ padding: '6px 12px', fontSize: 12 }}
                                            onClick={() => router.get(route('tasks.show', task.id))}
                                        >
                                            Update
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {upcoming.length === 0 && <EmptyState>No upcoming deadlines</EmptyState>}
            </div>
        </AppShell>
    );
}
