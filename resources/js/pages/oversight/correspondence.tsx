import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import Pagination, { type PaginationMeta } from '@/components/ats/pagination';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface CorrespondenceItem {
    id: number;
    author: string;
    author_role: string | null;
    text: string | null;
    when_label: string;
    task_id: number;
    task_reference: string | null;
    task_title: string | null;
}

interface CorrespondenceGroup {
    officer: string;
    officer_title: string | null;
    items: CorrespondenceItem[];
}

interface Props {
    q: string;
    groups: CorrespondenceGroup[];
    meta: PaginationMeta;
}

export default function Correspondence({ q, groups, meta }: Props) {
    const [term, setTerm] = useState(q);

    const search = () => {
        router.get(route('correspondence.index'), term.trim() === '' ? {} : { q: term.trim() }, { preserveState: true });
    };

    return (
        <AppShell title="Annotations">
            <div className="page-hd">
                <div>
                    <h1>Annotations</h1>
                    <div className="page-sub">Notes and instructions on assignments you are authorised to view</div>
                </div>
            </div>
            <div className="filters-bar">
                <input
                    className="input"
                    style={{ width: 300 }}
                    type="text"
                    placeholder="Filter by staff member, assignment, or text…"
                    aria-label="Filter annotations"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    onKeyDown={(event) => event.key === 'Enter' && search()}
                />
                <button type="button" className="btn btn-primary" onClick={search}>
                    Filter
                </button>
                {q !== '' && (
                    <button type="button" className="btn btn-ghost" onClick={() => router.get(route('correspondence.index'))}>
                        Clear
                    </button>
                )}
            </div>

            {groups.map((group) => (
                <div key={group.officer} className="card" style={{ marginBottom: 16 }}>
                    <div className="card-hd">
                        <h3>{group.officer}</h3>
                        <span style={{ fontSize: 12, color: 'var(--label)' }}>{group.officer_title}</span>
                    </div>
                    {group.items.map((item) => (
                        <div key={item.id} className="annotation" style={{ marginBottom: 8 }}>
                            <div style={{ fontWeight: 600, fontSize: 12 }}>
                                {item.author}{' '}
                                <span style={{ color: 'var(--label)', fontWeight: 400 }}>
                                    {item.author_role ? `· ${item.author_role} ` : ''}· {item.when_label}
                                </span>
                            </div>
                            <div className="annotation-text" style={{ marginTop: 3 }}>{item.text}</div>
                            <div style={{ marginTop: 6, fontSize: 11.5 }}>
                                <Link href={route('tasks.show', item.task_id)}>
                                    {item.task_reference} — {item.task_title}
                                </Link>
                            </div>
                        </div>
                    ))}
                </div>
            ))}

            {groups.length === 0 && (
                <div className="card">
                    <EmptyState>{q === '' ? 'No annotations yet' : `No annotations match “${q}”`}</EmptyState>
                </div>
            )}

            <Pagination meta={meta} />
        </AppShell>
    );
}
