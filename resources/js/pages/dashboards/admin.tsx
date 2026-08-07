import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import { StatCard, StatGrid } from '@/components/ats/stat-card';
import { Timeline, TimelineItem } from '@/components/ats/timeline';
import { BarChart3, UserPlus, Users } from '@/components/icons';
import { router } from '@inertiajs/react';

interface Props {
    stats: { total_users: number; active_users: number; departments: number; tasks: number };
    recent_activity: { text: string; who: string; when_label: string }[];
    departments: { name: string; code: string; officer_count: number }[];
}

export default function AdminDashboard({ stats, recent_activity, departments }: Props) {
    return (
        <AppShell title="Admin Dashboard">
            <h1>Admin Dashboard</h1>
            <div style={{ marginTop: 20 }}>
                <StatGrid>
                    <StatCard label="Total User Accounts" value={stats.total_users} />
                    <StatCard label="Active Accounts" value={stats.active_users} />
                    <StatCard label="Departments" value={stats.departments} />
                    <StatCard label="Total Tasks" value={stats.tasks} />
                </StatGrid>
            </div>
            <div className="grid2">
                <div className="card">
                    <div className="card-hd">
                        <h3>Recent Activity</h3>
                    </div>
                    <Timeline>
                        {recent_activity.map((activity, index) => (
                            <TimelineItem key={index} text={activity.text} meta={`${activity.who} · ${activity.when_label}`} />
                        ))}
                    </Timeline>
                    {recent_activity.length === 0 && <EmptyState>No recent activity</EmptyState>}
                </div>
                <div className="card">
                    <div className="card-hd">
                        <h3>Quick Actions</h3>
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                        <button
                            type="button"
                            className="btn btn-ghost"
                            style={{ justifyContent: 'flex-start' }}
                            onClick={() => router.get(route('admin.users.index'), { new: 1 })}
                        >
                            <UserPlus aria-hidden="true" />
                            Create User Account
                        </button>
                        <button
                            type="button"
                            className="btn btn-ghost"
                            style={{ justifyContent: 'flex-start' }}
                            onClick={() => router.get(route('admin.users.index'))}
                        >
                            <Users aria-hidden="true" />
                            Manage Passwords
                        </button>
                        <button
                            type="button"
                            className="btn btn-ghost"
                            style={{ justifyContent: 'flex-start' }}
                            onClick={() => router.get(route('reports.index'))}
                        >
                            <BarChart3 aria-hidden="true" />
                            Open Reports
                        </button>
                    </div>
                    <div className="card-hd" style={{ marginTop: 20 }}>
                        <h3>Departments</h3>
                    </div>
                    {departments.map((department) => (
                        <div key={department.code} className="list-row">
                            <div style={{ flex: 1 }}>
                                <div style={{ fontWeight: 600, fontSize: 13 }}>{department.name}</div>
                                <div className="ref">{department.code}</div>
                            </div>
                            <span style={{ fontSize: 12, color: 'var(--label)' }}>{department.officer_count} officers</span>
                        </div>
                    ))}
                </div>
            </div>
        </AppShell>
    );
}
