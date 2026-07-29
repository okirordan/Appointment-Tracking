import AppShell from '@/components/ats/app-shell';
import { StatusBadge } from '@/components/ats/badges';
import EmptyState from '@/components/ats/empty-state';
import Pagination, { pageWindow, type PaginationMeta } from '@/components/ats/pagination';
import { cn } from '@/lib/utils';
import type { SharedData, TaskRow } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { Building2, FolderKanban, Mail, Search, UserRound, Workflow } from 'lucide-react';
import { FormEvent, ReactNode, useState } from 'react';

type ResultType = 'all' | 'mail' | 'tasks' | 'workstreams' | 'departments' | 'divisions' | 'staff';
interface NamedResult {
    id: number;
    name: string;
    code: string | null;
}
interface OfficerResult {
    id: number;
    full_name: string;
    title: string | null;
    initials: string;
}
interface DepartmentResult extends NamedResult {
    officer_count: number;
}
interface DivisionResult extends NamedResult {
    department_id: number;
    department_name: string;
}
interface WorkstreamResult extends NamedResult {
    type: string;
}
interface TaskResult extends TaskRow {
    description: string | null;
    division_name: string | null;
    workstream_name: string | null;
}
interface MailResult {
    id: number;
    direction: 'incoming' | 'outgoing';
    register_number: string;
    sender_name: string;
    sender_organisation: string | null;
    recipient_name: string;
    subject: string;
    details: string | null;
    correspondence_reference: string | null;
    mail_date_label: string;
    status: string;
    status_class: string;
}
interface SearchResults {
    mails: MailResult[];
    tasks: TaskResult[];
    officers: OfficerResult[];
    departments: DepartmentResult[];
    divisions: DivisionResult[];
    workstreams: WorkstreamResult[];
    counts: {
        mails: number;
        tasks: number;
        officers: number;
        departments: number;
        divisions: number;
        workstreams: number;
    };
    total: number;
    per_page: number;
    pagination: PaginationMeta | null;
    did_you_mean: {
        term: string;
        results: SearchResults;
    } | null;
}
interface Props {
    q: string;
    type: ResultType;
    results: SearchResults | null;
}

const filters: { value: ResultType; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'mail', label: 'Mail' },
    { value: 'tasks', label: 'Tasks' },
    { value: 'workstreams', label: 'Projects & subjects' },
    { value: 'departments', label: 'Departments' },
    { value: 'divisions', label: 'Divisions' },
    { value: 'staff', label: 'Staff' },
];

function Highlight({ text, term }: { text: string; term: string }) {
    if (term.length < 2) return <>{text}</>;
    const parts = text.split(new RegExp(`(${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig'));
    return <>{parts.map((part, index) => (part.toLowerCase() === term.toLowerCase() ? <mark key={index}>{part}</mark> : part))}</>;
}

/**
 * Pagination shown directly under a category in the "All" overview, so
 * users can reach every page of a large category immediately instead of
 * having to notice the "View all" shortcut first. Each page button opens
 * the category view at that page.
 */
function CategoryPagination({
    count,
    shown,
    perPage,
    label,
    onPage,
}: {
    count: number;
    shown: number;
    perPage: number;
    label: string;
    onPage: (page: number) => void;
}) {
    if (count <= shown) {
        return null;
    }
    const lastPage = Math.max(1, Math.ceil(count / perPage));

    return (
        <nav className="search-group-pagination" aria-label={`Pages of ${label}`}>
            <span>
                Showing the top {shown} of {count} {label}
            </span>
            <div className="search-group-pages">
                {pageWindow(1, lastPage).map((page, index) =>
                    page === '…' ? (
                        <span key={`gap-${index}`} className="pagination-ellipsis" aria-hidden="true">
                            …
                        </span>
                    ) : (
                        <button key={page} type="button" aria-label={`Open page ${page} of ${label}`} onClick={() => onPage(page)}>
                            {page}
                        </button>
                    ),
                )}
            </div>
        </nav>
    );
}

function Group({ title, icon, action, children }: { title: string; icon: ReactNode; action?: ReactNode; children: ReactNode }) {
    return (
        <section className="search-group">
            <h2>
                <span>
                    {icon}
                    {title}
                </span>
                {action}
            </h2>
            <div className="search-group-list">{children}</div>
        </section>
    );
}

export default function Home({ q, type, results }: Props) {
    const { auth } = usePage<SharedData>().props;
    const [term, setTerm] = useState(q);
    const [selectedType, setSelectedType] = useState<ResultType>(type);
    const minLength = 2;
    // Partial reload: only the search itself is recomputed server-side —
    // mail stats, recent tasks and recent searches are skipped, which is
    // the main reason searches respond faster.
    const searchOnly = ['q', 'type', 'results'];
    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        if (term.trim().length >= minLength) {
            router.get(route('home'), { q: term.trim(), type: selectedType }, { preserveState: true, only: searchOnly });
        }
    };
    const navigateType = (next: ResultType, page = 1) => {
        setSelectedType(next);
        if (term.trim().length >= minLength) {
            router.get(route('home'), { q: term.trim(), type: next, ...(page > 1 ? { page } : {}) }, { preserveState: true, only: searchOnly });
        }
    };
    const didYouMean = results?.did_you_mean ?? null;
    const displayedResults = didYouMean?.results ?? results;
    const displayedTerm = didYouMean?.term ?? q;
    const acceptSuggestion = () => {
        if (didYouMean === null) return;
        setTerm(didYouMean.term);
        router.get(route('home'), { q: didYouMean.term, type: selectedType }, { preserveState: true, only: searchOnly });
    };

    return (
        <AppShell title="Search">
            <div className={cn('home-search-hero', results && 'compact')}>
                <div className="home-search-intro">
                    <h1>Search ATS</h1>
                    <p>Search mail by subject, or find assignments, projects, departments, divisions, and staff.</p>
                </div>
                <form className="google-search" onSubmit={submit} role="search">
                    <Search aria-hidden="true" />
                    <input
                        autoFocus={!results}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        aria-label="Search the Assignment Tracking System"
                        placeholder="Search mail senders, task titles, subjects, references or staff"
                    />
                    <button type="submit" disabled={term.trim().length < minLength}>
                        Search
                    </button>
                </form>
                {term.length > 0 && term.trim().length < minLength && (
                    <div className="search-help" role="status">
                        Enter at least {minLength} characters.
                    </div>
                )}
                <div className="search-filters" aria-label="Result type">
                    {filters.map((filter) => (
                        <button
                            key={filter.value}
                            type="button"
                            className={selectedType === filter.value ? 'active' : ''}
                            aria-pressed={selectedType === filter.value}
                            onClick={() => navigateType(filter.value)}
                        >
                            {filter.label}
                        </button>
                    ))}
                </div>
            </div>

            {results && displayedResults && (
                <div className="search-results" aria-live="polite">
                    {didYouMean === null ? (
                        <div className="search-summary">
                            {displayedResults.total} permitted result{displayedResults.total === 1 ? '' : 's'} for <strong>“{q}”</strong>
                            {selectedType === 'all' && displayedResults.total > 0 && (
                                <small>Showing the strongest matches in each category. Open a category to browse every result.</small>
                            )}
                        </div>
                    ) : (
                        <div className="did-you-mean" role="status">
                            <span className="did-you-mean-icon">
                                <Search aria-hidden="true" />
                            </span>
                            <div>
                                <span>No exact results for “{q}”.</span>
                                <button type="button" onClick={acceptSuggestion}>
                                    Did you mean <strong>“{didYouMean.term}”</strong>?
                                </button>
                                <small>
                                    Showing {displayedResults.total} close result{displayedResults.total === 1 ? '' : 's'} below.
                                </small>
                            </div>
                        </div>
                    )}
                    {displayedResults.mails.length > 0 && (
                        <Group
                            title="Mail correspondence"
                            icon={<Mail aria-hidden="true" />}
                            action={
                                selectedType === 'all' && displayedResults.counts.mails > displayedResults.mails.length ? (
                                    <button type="button" onClick={() => navigateType('mail')}>
                                        View all {displayedResults.counts.mails}
                                    </button>
                                ) : undefined
                            }
                        >
                            {displayedResults.mails.map((mail) => (
                                <Link className="search-result" key={mail.id} href={route('mail.show', mail.id)}>
                                    <div className="search-result-main">
                                        <span className="result-eyebrow">
                                            {mail.direction === 'incoming' ? 'Incoming mail' : 'Outgoing mail'} · {mail.register_number}
                                            {mail.correspondence_reference ? ` · ${mail.correspondence_reference}` : ''}
                                        </span>
                                        <h3>
                                            <Highlight text={mail.subject} term={displayedTerm} />
                                        </h3>
                                        <p>
                                            From <Highlight text={mail.sender_name} term={displayedTerm} />
                                            {mail.sender_organisation ? ` · ${mail.sender_organisation}` : ''} · To{' '}
                                            <Highlight text={mail.recipient_name} term={displayedTerm} />
                                        </p>
                                        {mail.details && (
                                            <p>
                                                <Highlight text={mail.details.slice(0, 180)} term={displayedTerm} />
                                            </p>
                                        )}
                                    </div>
                                    <div className="result-meta">
                                        <StatusBadge label={mail.status} badgeClass={mail.status_class} />
                                        <span>{mail.mail_date_label}</span>
                                    </div>
                                </Link>
                            ))}
                            {selectedType === 'all' && (
                                <CategoryPagination
                                    count={displayedResults.counts.mails}
                                    shown={displayedResults.mails.length}
                                    perPage={displayedResults.per_page}
                                    label="mail results"
                                    onPage={(page) => navigateType('mail', page)}
                                />
                            )}
                        </Group>
                    )}
                    {displayedResults.tasks.length > 0 && (
                        <Group
                            title="Tasks"
                            icon={<Workflow aria-hidden="true" />}
                            action={
                                selectedType === 'all' && displayedResults.counts.tasks > displayedResults.tasks.length ? (
                                    <button type="button" onClick={() => navigateType('tasks')}>
                                        View all {displayedResults.counts.tasks}
                                    </button>
                                ) : undefined
                            }
                        >
                            {displayedResults.tasks.map((task) => (
                                <Link className="search-result" key={task.id} href={route('tasks.show', task.id)}>
                                    <div className="search-result-main">
                                        <span className="result-eyebrow">
                                            {task.reference}
                                            {task.workstream_name ? ` · ${task.workstream_name}` : ''}
                                        </span>
                                        <h3>
                                            <Highlight text={task.title} term={displayedTerm} />
                                        </h3>
                                        <p>
                                            {task.description ? (
                                                <Highlight text={task.description.slice(0, 180)} term={displayedTerm} />
                                            ) : (
                                                'No description provided.'
                                            )}
                                        </p>
                                    </div>
                                    <div className="result-meta">
                                        <StatusBadge label={task.status} badgeClass={task.status_class} />
                                        <span>
                                            {task.department_name}
                                            {task.division_name ? ` · ${task.division_name}` : ''}
                                        </span>
                                    </div>
                                </Link>
                            ))}
                            {selectedType === 'all' && (
                                <CategoryPagination
                                    count={displayedResults.counts.tasks}
                                    shown={displayedResults.tasks.length}
                                    perPage={displayedResults.per_page}
                                    label="task results"
                                    onPage={(page) => navigateType('tasks', page)}
                                />
                            )}
                        </Group>
                    )}
                    {displayedResults.workstreams.length > 0 && (
                        <Group
                            title="Projects & subjects"
                            icon={<FolderKanban aria-hidden="true" />}
                            action={
                                selectedType === 'all' && displayedResults.counts.workstreams > displayedResults.workstreams.length ? (
                                    <button type="button" onClick={() => navigateType('workstreams')}>
                                        View all {displayedResults.counts.workstreams}
                                    </button>
                                ) : undefined
                            }
                        >
                            {displayedResults.workstreams.map((item) => (
                                <Link className="search-result" key={item.id} href={route('tasks.index', { workstream: item.id })}>
                                    <div>
                                        <span className="result-eyebrow">
                                            {item.type}
                                            {item.code ? ` · ${item.code}` : ''}
                                        </span>
                                        <h3>
                                            <Highlight text={item.name} term={displayedTerm} />
                                        </h3>
                                    </div>
                                </Link>
                            ))}
                            {selectedType === 'all' && (
                                <CategoryPagination
                                    count={displayedResults.counts.workstreams}
                                    shown={displayedResults.workstreams.length}
                                    perPage={displayedResults.per_page}
                                    label="projects & subjects"
                                    onPage={(page) => navigateType('workstreams', page)}
                                />
                            )}
                        </Group>
                    )}
                    {displayedResults.departments.length > 0 && (
                        <Group
                            title="Departments"
                            icon={<Building2 aria-hidden="true" />}
                            action={
                                selectedType === 'all' && displayedResults.counts.departments > displayedResults.departments.length ? (
                                    <button type="button" onClick={() => navigateType('departments')}>
                                        View all {displayedResults.counts.departments}
                                    </button>
                                ) : undefined
                            }
                        >
                            {displayedResults.departments.map((item) => (
                                <Link
                                    className="search-result"
                                    key={item.id}
                                    href={
                                        auth.user!.role === 'clerk'
                                            ? route('tasks.index', { department: item.id })
                                            : route('performance.index', { department: item.id })
                                    }
                                >
                                    <div>
                                        <span className="result-eyebrow">{item.code}</span>
                                        <h3>
                                            <Highlight text={item.name} term={displayedTerm} />
                                        </h3>
                                        <p>{item.officer_count} active officers</p>
                                    </div>
                                </Link>
                            ))}
                            {selectedType === 'all' && (
                                <CategoryPagination
                                    count={displayedResults.counts.departments}
                                    shown={displayedResults.departments.length}
                                    perPage={displayedResults.per_page}
                                    label="departments"
                                    onPage={(page) => navigateType('departments', page)}
                                />
                            )}
                        </Group>
                    )}
                    {displayedResults.divisions.length > 0 && (
                        <Group
                            title="Divisions"
                            icon={<Building2 aria-hidden="true" />}
                            action={
                                selectedType === 'all' && displayedResults.counts.divisions > displayedResults.divisions.length ? (
                                    <button type="button" onClick={() => navigateType('divisions')}>
                                        View all {displayedResults.counts.divisions}
                                    </button>
                                ) : undefined
                            }
                        >
                            {displayedResults.divisions.map((item) => (
                                <Link
                                    className="search-result"
                                    key={item.id}
                                    href={route('performance.index', { department: item.department_id, division: item.id })}
                                >
                                    <div>
                                        <span className="result-eyebrow">
                                            {item.department_name} · {item.code}
                                        </span>
                                        <h3>
                                            <Highlight text={item.name} term={displayedTerm} />
                                        </h3>
                                    </div>
                                </Link>
                            ))}
                            {selectedType === 'all' && (
                                <CategoryPagination
                                    count={displayedResults.counts.divisions}
                                    shown={displayedResults.divisions.length}
                                    perPage={displayedResults.per_page}
                                    label="divisions"
                                    onPage={(page) => navigateType('divisions', page)}
                                />
                            )}
                        </Group>
                    )}
                    {displayedResults.officers.length > 0 && (
                        <Group
                            title="Staff"
                            icon={<UserRound aria-hidden="true" />}
                            action={
                                selectedType === 'all' && displayedResults.counts.officers > displayedResults.officers.length ? (
                                    <button type="button" onClick={() => navigateType('staff')}>
                                        View all {displayedResults.counts.officers}
                                    </button>
                                ) : undefined
                            }
                        >
                            {displayedResults.officers.map((item) => (
                                <Link
                                    className="search-result"
                                    key={item.id}
                                    href={
                                        auth.user!.role === 'clerk'
                                            ? route('lookup.index', { q: item.full_name, officer: item.id })
                                            : route('performance.show', item.id)
                                    }
                                >
                                    <div className="avatar">{item.initials}</div>
                                    <div>
                                        <h3>
                                            <Highlight text={item.full_name} term={displayedTerm} />
                                        </h3>
                                        <p>{item.title || 'Staff member'}</p>
                                    </div>
                                </Link>
                            ))}
                            {selectedType === 'all' && (
                                <CategoryPagination
                                    count={displayedResults.counts.officers}
                                    shown={displayedResults.officers.length}
                                    perPage={displayedResults.per_page}
                                    label="staff results"
                                    onPage={(page) => navigateType('staff', page)}
                                />
                            )}
                        </Group>
                    )}
                    {displayedResults.total === 0 && (
                        <div className="card">
                            <EmptyState>
                                No permitted results match “{q}”. Try a mail sender, reference, project, department or staff title.
                            </EmptyState>
                        </div>
                    )}
                    {displayedResults.pagination && <Pagination meta={displayedResults.pagination} only={searchOnly} />}
                </div>
            )}
        </AppShell>
    );
}
