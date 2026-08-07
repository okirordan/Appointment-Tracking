import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import Pagination from '@/components/ats/pagination';
import { StatCard, StatGrid } from '@/components/ats/stat-card';
import { Timeline, TimelineItem } from '@/components/ats/timeline';
import { BarChart3, UserPlus, Users } from '@/components/icons';
import type { PaginatedData } from '@/types';
import { router } from '@inertiajs/react';

interface Props {
    stats: { total_users: number; active_users: number; departments: number; tasks: number };
    recent_activity: PaginatedData<{ text: string; who: string; when_label: string }>;
    departments: PaginatedData<{ name: string; code: string; officer_count: number }>;
}

export default function AdminDashboard({ stats, recent_activity, departments }: Props) {
    return (
        <AppShell title="Admin Dashboard">
            <h1>Admin Dashboard</h1>
            <div className="admin-dashboard-metrics">
                <StatGrid>
                    <StatCard label="Total User Accounts" value={stats.total_users} />
                    <StatCard label="Active Accounts" value={stats.active_users} />
                    <StatCard label="Departments" value={stats.departments} />
                    <StatCard label="Total Tasks" value={stats.tasks} />
                </StatGrid>
            </div>
            <div className="admin-dashboard-stack">
                <div className="card">
                    <div className="card-hd">
                        <div>
                            <h3>Quick Actions</h3>
                            <p className="admin-dashboard-section-copy">Common account and reporting actions.</p>
                        </div>
                    </div>
                    <div className="admin-quick-actions">
                        <button type="button" className="btn btn-ghost" onClick={() => router.get(route('admin.users.index'), { new: 1 })}>
                            <UserPlus aria-hidden="true" />
                            Create User Account
                        </button>
                        <button type="button" className="btn btn-ghost" onClick={() => router.get(route('admin.users.index'))}>
                            <Users aria-hidden="true" />
                            Manage Passwords
                        </button>
                        <button type="button" className="btn btn-ghost" onClick={() => router.get(route('reports.index'))}>
                            <BarChart3 aria-hidden="true" />
                            Open Reports
                        </button>
                    </div>
                </div>

                <div className="card admin-dashboard-list-card">
                    <div className="card-hd">
                        <div>
                            <h3>Recent Activity</h3>
                            <p className="admin-dashboard-section-copy">Latest non-correspondence administration events.</p>
                        </div>
                    </div>
                    <Timeline>
                        {recent_activity.data.map((activity, index) => (
                            <TimelineItem
                                key={`${activity.when_label}-${index}`}
                                text={activity.text}
                                meta={`${activity.who} · ${activity.when_label}`}
                            />
                        ))}
                    </Timeline>
                    {recent_activity.data.length === 0 && <EmptyState>No recent activity</EmptyState>}
                    <Pagination meta={recent_activity.meta} pageParam="activity_page" only={['recent_activity']} />
                </div>

                <div className="card admin-dashboard-list-card">
                    <div className="card-hd">
                        <div>
                            <h3>Departments</h3>
                            <p className="admin-dashboard-section-copy">Each organisational unit is shown on its own row.</p>
                        </div>
                    </div>
                    <div className="admin-department-list">
                        {departments.data.map((department) => (
                            <div key={department.code} className="list-row">
                                <div className="admin-department-copy">
                                    <div className="admin-department-name">{department.name}</div>
                                    <div className="ref">{department.code}</div>
                                </div>
                                <span className="admin-department-count">{department.officer_count} officers</span>
                            </div>
                        ))}
                    </div>
                    {departments.data.length === 0 && <EmptyState>No active departments</EmptyState>}
                    <Pagination meta={departments.meta} pageParam="department_page" only={['departments']} />
                </div>
            </div>
        </AppShell>
    );
}
