import { router } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '@/components/ats/app-shell';
import { OverdueTag, StatusBadge } from '@/components/ats/badges';
import EmptyState from '@/components/ats/empty-state';
import ProgressBar from '@/components/ats/progress-bar';
import type { TaskRow } from '@/types';

interface OfficerSummary {
    id: number;
    full_name: string;
    title: string | null;
    department_name: string;
    initials: string;
    assigned: number;
    completed: number;
    overdue: number;
}

interface PortfolioTask extends TaskRow {
    assigned_label: string;
    completed_label: string;
}

interface SelectedOfficer extends OfficerSummary {
    tasks: PortfolioTask[];
    status_distribution: { label: string; count: number; pct: number }[];
}

interface Props {
    q: string;
    officers: OfficerSummary[];
    selected: SelectedOfficer | null;
}

export default function OfficerLookup({ q, officers, selected }: Props) {
    const [term, setTerm] = useState(q);

    const search = () => {
        router.get(route('lookup.index'), term.trim() === '' ? {} : { q: term.trim() }, { preserveState: true });
    };

    const openOfficer = (id: number) => {
        router.get(route('lookup.index'), { q, officer: id }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppShell title="Officer Lookup">
            <div className="page-hd">
                <div>
                    <h1>Officer Lookup</h1>
                    <div className="page-sub">Find an officer and review their assignment portfolio</div>
                </div>
            </div>
            <div className="filters-bar">
                <input
                    className="input"
                    style={{ width: 300 }}
                    type="text"
                    placeholder="Search officer name or position (min 2 letters)…"
                    aria-label="Search officers"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    onKeyDown={(event) => event.key === 'Enter' && search()}
                />
                <button type="button" className="btn btn-primary" onClick={search}>
                    Search
                </button>
            </div>

            {q.length >= 2 && officers.length === 0 && (
                <div className="card">
                    <EmptyState>No officer matches “{q}”</EmptyState>
                </div>
            )}

            {officers.length > 0 && (
                <div className="card" style={{ marginBottom: 18 }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Officer</th>
                                <th>Department</th>
                                <th>Assigned</th>
                                <th>Completed</th>
                                <th>Overdue</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {officers.map((officer) => (
                                <tr key={officer.id} className="row" onClick={() => openOfficer(officer.id)}>
                                    <td>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                                            <div className="avatar" style={{ width: 28, height: 28, fontSize: 11 }}>
                                                {officer.initials}
                                            </div>
                                            <div>
                                                <div style={{ fontWeight: 600 }}>{officer.full_name}</div>
                                                <div style={{ fontSize: 11.5, color: 'var(--label)' }}>{officer.title}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{officer.department_name}</td>
                                    <td>{officer.assigned}</td>
                                    <td>{officer.completed}</td>
                                    <td>{officer.overdue > 0 ? <OverdueTag>{officer.overdue}</OverdueTag> : 0}</td>
                                    <td>
                                        <button type="button" className="btn btn-ghost" style={{ padding: '6px 12px', fontSize: 12 }}>
                                            View
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {selected !== null && (
                <div className="card">
                    <div className="card-hd">
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                            <div className="avatar">{selected.initials}</div>
                            <div>
                                <h3>{selected.full_name}</h3>
                                <div style={{ fontSize: 11.5, color: 'var(--label)' }}>
                                    {selected.title} · {selected.department_name}
                                </div>
                            </div>
                        </div>
                        <span style={{ fontSize: 12, color: 'var(--label)' }}>
                            {selected.assigned} assigned · {selected.completed} completed · {selected.overdue} overdue
                        </span>
                    </div>
                    <div style={{ overflowX: 'auto' }}>
                        <table className="tbl">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Title</th>
                                    <th>Assigned</th>
                                    <th>Due</th>
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
                                        <td>
                                            <StatusBadge label={task.status} badgeClass={task.status_class} />
                                        </td>
                                        <td style={{ minWidth: 90 }}>
                                            <ProgressBar percent={task.progress} variant={task.progress_class} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {selected.tasks.length === 0 && <EmptyState>No assignments for this officer</EmptyState>}
                </div>
            )}
        </AppShell>
    );
}
