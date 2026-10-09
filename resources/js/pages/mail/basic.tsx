import FlashBridge from '@/components/ats/flash-bridge';
import ImpersonationBanner from '@/components/ats/impersonation-banner';
import ThemeSelector from '@/components/ats/theme-selector';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import BasicCorrespondenceList from './basic-correspondence-list';
import { BasicAssignmentForm, BasicCorrespondenceForm, BasicMailForm } from './basic-forms';
import BasicProfileMenu from './basic-profile-menu';
import { basicRoute, BasicRouteFrom } from './basic-route';
import './basic.css';
import type { MailDetail, Props } from './index';
import MailModeSwitch, { useMailUrl } from './mode';
import MailStatusForm from './status-form';

export type BasicWorkflowAction = 'forward' | 'outgoing' | 'file' | 'reopen' | 'unassign' | 'department';
type BasicProps = Props & { renderWorkflow: (action: BasicWorkflowAction, close: () => void) => ReactNode };

export default function BasicMail(props: BasicProps) {
    const url = useMailUrl();
    const { url: current } = usePage();
    const [workflow, setWorkflow] = useState<BasicWorkflowAction | null>(null);
    const { selectedMail: mail, direction } = props;
    const view = new URL(current, 'http://ats.local').searchParams.get('view') ?? props.mailView;
    const home = view === 'home' && !mail;
    const title =
        mail?.subject ??
        (home ? 'Mail Management System' : direction === 'outgoing' ? 'Outgoing Mail' : direction === 'filed' ? 'Filed Mail' : 'Incoming Mail');
    const register = direction === 'outgoing' ? 'mail.outgoing.index' : direction === 'filed' ? 'mail.filed.index' : 'mail.incoming.index';
    const clean = { view: null, section: null, page: null, q: null, status: null, recipient: null, date_from: null, date_to: null, category: null };
    return (
        <div className="mail-basic">
            <Head title={title} />
            <FlashBridge />
            <header className="site-header">
                <div className="topbar">
                    <Link className="ats-brand" href={url('/home?type=all', clean)}>
                        <img src="/images/moes-crest.jpg" alt="Uganda coat of arms" />
                        <span>
                            <strong>MoES</strong>
                            <small>Mail Management System</small>
                        </span>
                    </Link>
                    <nav aria-label="Mail navigation">
                        <Link className="nav-item" href={url('/home?type=all', clean)} aria-current={home ? 'page' : undefined}>
                            Home
                        </Link>
                        <Link
                            className="nav-item"
                            href={url(route('mail.incoming.index'), clean)}
                            aria-current={!home && direction === 'incoming' ? 'page' : undefined}
                        >
                            Incoming Mail
                        </Link>
                        <Link
                            className="nav-item"
                            href={url(route('mail.outgoing.index'), clean)}
                            aria-current={!home && direction === 'outgoing' ? 'page' : undefined}
                        >
                            Outgoing Mail
                        </Link>
                    </nav>
                    <MailModeSwitch />
                    <ThemeSelector compact />
                    <BasicProfileMenu />
                </div>
            </header>
            <ImpersonationBanner />
            <main>
                {mail ? (
                    <BasicDetail key={mail.id} props={props} mail={mail} onWorkflow={setWorkflow} />
                ) : view === 'new' && props.canManageRegister && direction !== 'filed' ? (
                    <>
                        <Link className="btn g back" href={url(route(register), { view: null })}>
                            ‹ {title}
                        </Link>
                        <div className="head">
                            <div>
                                <h1>New {direction === 'incoming' ? 'Incoming' : 'Outgoing'} Mail</h1>
                                <p className="sub">{direction === 'incoming' ? 'Log a newly received item' : 'Log an outgoing item'}</p>
                            </div>
                        </div>
                        <BasicMailForm props={props} />
                    </>
                ) : home ? (
                    <BasicHome props={props} />
                ) : (
                    <BasicRegister props={props} />
                )}
            </main>
            <footer className="global-footer">
                <span className="footer-flag" aria-hidden="true" />
                <span>
                    Developed by <strong>Department of Libraries, E-Learning and Information Technology</strong>
                </span>
            </footer>
            {workflow && props.renderWorkflow(workflow, () => setWorkflow(null))}
        </div>
    );
}

function BasicHome({ props }: { props: Props }) {
    const url = useMailUrl();
    const [query, setQuery] = useState(props.filters.q);
    const { url: currentUrl } = usePage();
    const searchType = new URL(currentUrl, 'http://ats.local').searchParams.get('type') ?? 'all';
    const incoming = url(route('mail.incoming.index'), { view: null, section: null, page: null });
    const outgoing = url(route('mail.outgoing.index'), { view: null, section: null, page: null });
    return (
        <>
            <section className="hero">
                <img className="hero-crest" src="/images/moes-crest.jpg" alt="" />
                <h1>Ministry of Education &amp; Sports</h1>
                <p className="sub">Mail Management System</p>
                <form
                    className="home-search"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(url('/home?type=all', { view: null, q: query, page: null }));
                    }}
                >
                    <label htmlFor="home-q">Search the system</label>
                    <div className="search-line">
                        <input
                            id="home-q"
                            type="search"
                            placeholder="Search mail, correspondence, officers or tasks"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                        <button className="btn p">Search</button>
                    </div>
                </form>
                <div className="quick-links">
                    <Link className="btn p" href={incoming}>
                        View incoming mail
                    </Link>
                    <Link className="btn" href={outgoing}>
                        View outgoing mail
                    </Link>
                    {props.canManageRegister && (
                        <Link className="btn" href={url(route('mail.incoming.index'), { view: 'new', page: null })}>
                            + Log incoming mail
                        </Link>
                    )}
                </div>
            </section>
            {props.results && (
                <section className="dashboard card pad">
                    <h2>Search results ({props.results.total})</h2>
                    <div className="recent-list">
                        {props.results.mails.map((mail) => (
                            <Link key={mail.id} href={url(route('mail.show', mail.id), { view: null, section: 'details' })}>
                                <div>
                                    <strong>{mail.subject}</strong>
                                    <br />
                                    <span>
                                        {mail.sender_display || mail.sender_name} · {mail.mailbox_direction ?? mail.direction} · Mail / Correspondence
                                    </span>
                                </div>
                                <span>{mail.mail_date_label}</span>
                            </Link>
                        ))}
                    </div>
                    <nav className="acts" aria-label="Search categories">
                        {['all', 'mail', 'tasks', 'departments', 'staff', 'divisions', 'workstreams'].map((type) => (
                            <Link
                                className="btn g"
                                key={type}
                                href={url('/home', { type, q: query, page: null, view: null })}
                                aria-current={searchType === type ? 'page' : undefined}
                            >
                                {type === 'all' ? 'All records' : type}
                            </Link>
                        ))}
                    </nav>
                    <div className="recent-list">
                        {props.results.tasks?.map((task) => (
                            <Link key={task.id} href={route('tasks.show', task.id)}>
                                <strong>{task.title}</strong>
                                <span>Task · {task.reference}</span>
                            </Link>
                        ))}
                        {props.results.departments?.map((department) => (
                            <Link key={department.id} href={route('tasks.index', { department: department.id })}>
                                <strong>{department.code || department.name}</strong>
                                <span>Department · {department.name}</span>
                            </Link>
                        ))}
                        {props.results.officers?.map((officer) => (
                            <Link key={officer.id} href={route('performance.show', officer.id)}>
                                <strong>{officer.full_name}</strong>
                                <span>Officer · {officer.title}</span>
                            </Link>
                        ))}
                        {props.results.divisions?.map((division) => (
                            <Link key={division.id} href={route('performance.index', { department: division.department_id, division: division.id })}>
                                <strong>{division.name}</strong>
                                <span>Division</span>
                            </Link>
                        ))}
                        {props.results.workstreams?.map((workstream) => (
                            <Link key={workstream.id} href={route('tasks.index', { workstream: workstream.id })}>
                                <strong>{workstream.code || workstream.name}</strong>
                                <span>Workstream · {workstream.name}</span>
                            </Link>
                        ))}
                    </div>
                    {props.results.total === 0 && <p>No records match your search.</p>}
                    {props.results.pagination && (
                        <div className="pager-controls">
                            <button
                                className="btn"
                                disabled={props.results.pagination.current_page <= 1}
                                onClick={() => router.get(url(`/home?type=${searchType}`, { page: props.results!.pagination!.current_page - 1 }))}
                            >
                                Previous
                            </button>
                            <span>
                                Page {props.results.pagination.current_page} of {props.results.pagination.last_page}
                            </span>
                            <button
                                className="btn"
                                disabled={props.results.pagination.current_page >= props.results.pagination.last_page}
                                onClick={() => router.get(url(`/home?type=${searchType}`, { page: props.results!.pagination!.current_page + 1 }))}
                            >
                                Next
                            </button>
                        </div>
                    )}
                </section>
            )}
            <section className="dashboard">
                <h2>Registry overview</h2>
                <div className="metric-grid">
                    <div className="card metric">
                        <span>Incoming mail</span>
                        <strong>{props.stats.incoming_total}</strong>
                        <Link href={incoming}>View records →</Link>
                    </div>
                    <div className="card metric">
                        <span>Outgoing mail</span>
                        <strong>{props.stats.outgoing_total}</strong>
                        <Link href={outgoing}>View records →</Link>
                    </div>
                    <div className="card metric">
                        <span>Open action points</span>
                        <strong>{props.openActionCount}</strong>
                        <Link href={url(route('mail.incoming.index'), { view: null, status: 'assigned', page: null })}>Review mail →</Link>
                    </div>
                </div>
                <div className="card pad">
                    <h2>Recent incoming mail</h2>
                    <div className="recent-list">
                        {props.recentMail.map((mail) => (
                            <Link key={mail.id} href={url(route('mail.show', mail.id), { view: null, section: 'details' })}>
                                <div>
                                    <strong>{mail.subject}</strong>
                                    <br />
                                    <span>{mail.sender_display || mail.sender_name}</span>
                                </div>
                                <span>{mail.mail_date_label}</span>
                            </Link>
                        ))}
                        {props.recentMail.length === 0 && <p className="empty">No incoming mail has been recorded.</p>}
                    </div>
                </div>
            </section>
        </>
    );
}

function BasicRegister({ props }: { props: Props }) {
    const { direction, filters, mails } = props;
    const url = useMailUrl();
    const [query, setQuery] = useState(filters.q);
    const index = route(direction === 'outgoing' ? 'mail.outgoing.index' : direction === 'filed' ? 'mail.filed.index' : 'mail.incoming.index');
    const title = direction === 'incoming' ? 'Incoming Mail' : direction === 'outgoing' ? 'Outgoing Mail' : 'Filed Mail';
    const apply = useCallback(
        (changes: Record<string, string | number | null>) =>
            router.get(url(index, { ...changes, view: null, section: null, page: null }), {}, { preserveState: true, preserveScroll: true }),
        [url, index],
    );
    useEffect(() => {
        setQuery(filters.q);
    }, [filters.q]);
    const clear = () => {
        setQuery('');
        apply(Object.fromEntries(Object.keys(filters).map((key) => [key, null])));
    };
    return (
        <>
            <div className="head">
                <div>
                    <h1>{title}</h1>
                    <p className="sub">
                        {direction === 'incoming'
                            ? 'Mail received and logged by the registry'
                            : direction === 'outgoing'
                              ? 'Mail sent and forwarded by the registry'
                              : 'Correspondence filed for reference'}
                    </p>
                </div>
                <div className="acts">
                    {props.canManageRegister && direction !== 'filed' && (
                        <Link className="btn p" href={url(index, { view: 'new' })}>
                            + New mail
                        </Link>
                    )}
                    <button className="btn" onClick={() => window.print()}>
                        Print
                    </button>
                </div>
            </div>
            <form
                className="card toolbar"
                role="search"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get(url('/home?type=all', { q: query, view: null, section: null, page: null }));
                }}
            >
                <div className="toolbar-grid">
                    <div className="search-control">
                        <label htmlFor="mail-search">Search {direction} mail</label>
                        <input
                            id="mail-search"
                            type="search"
                            placeholder="Sender, subject or reference"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </div>
                    {direction === 'incoming' ? (
                        <div>
                            <label htmlFor="mail-recipient">Recipient</label>
                            <select id="mail-recipient" value={filters.recipient} onChange={(event) => apply({ recipient: event.target.value })}>
                                <option value="">All recipients</option>
                                {props.recipientOptions.map((name) => (
                                    <option key={name}>{name}</option>
                                ))}
                            </select>
                        </div>
                    ) : (
                        <div>
                            <label htmlFor="mail-status">Status</label>
                            <select id="mail-status" value={filters.status} onChange={(event) => apply({ status: event.target.value })}>
                                <option value="">All statuses</option>
                                {props.statusOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div>
                        <label htmlFor="date-from">From date</label>
                        <input id="date-from" type="date" value={filters.date_from} onChange={(event) => apply({ date_from: event.target.value })} />
                    </div>
                    <div>
                        <label htmlFor="date-to">To date</label>
                        <input id="date-to" type="date" value={filters.date_to} onChange={(event) => apply({ date_to: event.target.value })} />
                    </div>
                    <button className="btn g" type="button" onClick={clear}>
                        Clear filters
                    </button>
                </div>
            </form>
            <div className="card">
                <div className="tw">
                    <table aria-label={title}>
                        <thead>
                            <tr>
                                <th scope="col">Subject</th>
                                <th scope="col">From</th>
                                <th scope="col">To</th>
                                <th scope="col">{direction === 'incoming' ? 'Received' : direction === 'filed' ? 'Filed' : 'Sent / forwarded'}</th>
                                <th scope="col">{direction === 'incoming' ? 'Reference' : 'Status'}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {mails.data.map((mail) => {
                                const movement = basicRoute(mail, direction);
                                return (
                                    <tr key={mail.id}>
                                        <td>
                                            <Link className="table-link" href={url(route('mail.show', mail.id), { view: null, section: 'details' })}>
                                                {mail.subject}
                                            </Link>
                                        </td>
                                        <td>
                                            <BasicRouteFrom route={movement} />
                                        </td>
                                        <td>
                                            <span className="chip">{movement.to}</span>
                                        </td>
                                        <td className="mute">{direction === 'filed' ? mail.filed_at_label : movement.date}</td>
                                        <td>
                                            {direction === 'incoming' ? (
                                                mail.correspondence_reference || 'Not provided'
                                            ) : (
                                                <span className="chip">{mail.status}</span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                {mails.data.length === 0 && (
                    <div className="empty">
                        <strong>No {direction} mail matches these filters.</strong>
                        <p>
                            <button className="text-button" onClick={clear}>
                                Clear filters
                            </button>
                        </p>
                    </div>
                )}
                <div className="pager">
                    <span>
                        {mails.meta.total === 0
                            ? '0 records'
                            : `${mails.meta.from ?? 1}–${(mails.meta.from ?? 1) + mails.data.length - 1} of ${mails.meta.total} records`}
                    </span>
                    <div className="pager-controls">
                        <button
                            className="btn"
                            disabled={mails.meta.current_page <= 1}
                            onClick={() => router.get(url(index, { page: mails.meta.current_page - 1 }))}
                        >
                            Previous
                        </button>
                        <span>
                            Page {mails.meta.current_page} of {mails.meta.last_page}
                        </span>
                        <button
                            className="btn"
                            disabled={mails.meta.current_page >= mails.meta.last_page}
                            onClick={() => router.get(url(index, { page: mails.meta.current_page + 1 }))}
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
            <p className="mail-context">
                {props.registerOfficeName} ·{' '}
                <Link href={url(route('mail.filed.index'), { section: null, view: null, page: null, status: null })}>Filed correspondence</Link>
            </p>
        </>
    );
}

function BasicDetail({ props, mail, onWorkflow }: { props: Props; mail: MailDetail; onWorkflow: (action: BasicWorkflowAction) => void }) {
    const url = useMailUrl();
    const { url: current } = usePage();
    const params = new URL(current, 'http://ats.local').searchParams;
    const section = params.get('section') ?? 'details';
    const editing = params.get('view') === 'edit' && mail.can_edit;
    const showUrl = (next: string) => url(route('mail.show', mail.id), { section: next, view: null });
    const actualIncoming = props.direction !== 'outgoing' && mail.direction === 'incoming';
    const movement = basicRoute(mail, props.direction);
    const date = props.direction === 'outgoing' ? movement.date : mail.edit_values[actualIncoming ? 'received_date' : 'sent_date'];
    const label = props.direction === 'outgoing' ? 'Outgoing' : actualIncoming ? 'Incoming' : 'Outgoing';
    const addAction = section === 'add-action' && (mail.can_assign || mail.can_assign_outgoing);
    const addNote = section === 'add-note' && mail.can_participate;
    const back = props.canViewRegister
        ? url(
              route(
                  props.direction === 'outgoing' ? 'mail.outgoing.index' : props.direction === 'filed' ? 'mail.filed.index' : 'mail.incoming.index',
              ),
              { section: null, view: null },
          )
        : (mail.task_url ?? '/home');
    return (
        <>
            <Link className="btn g back" href={addAction ? showUrl('actions') : addNote ? showUrl('correspondences') : back}>
                ‹{' '}
                {addAction
                    ? 'Back to actions'
                    : addNote
                      ? 'Back to correspondences'
                      : props.direction === 'outgoing'
                        ? 'Outgoing Mail'
                        : props.direction === 'filed'
                          ? 'Filed Mail'
                          : 'Incoming Mail'}
            </Link>
            <div className="detail-heading">
                <div>
                    <p className="eyebrow">
                        {label} mail {mail.correspondence_reference ? `· ${mail.correspondence_reference}` : ''}
                    </p>
                    <h1>{addAction ? 'Add action point' : addNote ? 'Add correspondence' : mail.subject}</h1>
                    <p className="sub">
                        {addAction
                            ? 'Record what needs to be done'
                            : addNote
                              ? 'Log where this mail was sent or filed'
                              : `From ${movement.from} · ${actualIncoming ? 'Received' : movement.forwarded ? 'Forwarded' : 'Sent'} ${date || 'Not recorded'}`}
                    </p>
                </div>
                {!addAction && !addNote && (
                    <div className="acts">
                        {section === 'details' && mail.can_edit && !editing ? (
                            <Link className="btn p" href={url(route('mail.show', mail.id), { section: 'details', view: 'edit' })}>
                                Edit
                            </Link>
                        ) : section !== 'details' ? (
                            <Link className="btn" href={showUrl('details')}>
                                View details
                            </Link>
                        ) : null}
                        <button className="btn" onClick={() => window.print()}>
                            Print
                        </button>
                    </div>
                )}
            </div>
            {addAction ? (
                <BasicAssignmentForm mail={mail} />
            ) : addNote ? (
                <BasicCorrespondenceForm mail={mail} />
            ) : (
                <>
                    <nav className="tabs" aria-label="Mail sections">
                        {[
                            ['details', 'Details'],
                            ['actions', 'Action points'],
                            ['correspondences', 'Correspondences'],
                        ].map(([key, name]) => (
                            <Link key={key} href={showUrl(key)} aria-current={section === key ? 'page' : undefined}>
                                {name}
                            </Link>
                        ))}
                    </nav>
                    {section === 'actions' ? (
                        <section className="card pad">
                            <div className="section-head">
                                <h2>Action points</h2>
                                {(mail.can_assign || mail.can_assign_outgoing) && (
                                    <Link className="btn p" href={showUrl('add-action')}>
                                        + Add action point
                                    </Link>
                                )}
                            </div>
                            {mail.action_points.map((action) => (
                                <div className="item action-item" key={action.task_id}>
                                    <div>
                                        <strong>{action.instructions || mail.subject}</strong>
                                        <span className="item-meta">
                                            Assigned to {action.assigned_officer || 'Not recorded'} · Due {action.due_date_label || 'Not set'}
                                        </span>
                                        <Link className="table-link" href={action.url}>
                                            {action.reference} · Open task
                                        </Link>
                                    </div>
                                    <span className={`chip ${action.is_overdue ? 'warning' : ''}`}>
                                        {action.is_overdue ? 'Overdue · ' : ''}
                                        {action.status}
                                    </span>
                                </div>
                            ))}
                            {mail.action_points.length === 0 && (
                                <div className="empty">
                                    <strong>No action points yet</strong>
                                    <p>Add an action point to record the next step for this mail.</p>
                                </div>
                            )}
                        </section>
                    ) : section === 'correspondences' ? (
                        <section className="card pad">
                            <div className="section-head">
                                <h2>Correspondences</h2>
                                {mail.can_participate && (
                                    <Link className="btn p" href={showUrl('add-note')}>
                                        + Add correspondence
                                    </Link>
                                )}
                            </div>
                            <BasicCorrespondenceList entries={mail.basic_correspondences} />
                        </section>
                    ) : editing ? (
                        <BasicMailForm key={`edit-${mail.id}`} props={props} mail={mail} />
                    ) : (
                        <div className="card pad">
                            {movement.forwarded && <p className="basic-route-caption">Current movement</p>}
                            <dl className="detail-grid">
                                <div>
                                    <dt>From</dt>
                                    <dd>
                                        <BasicRouteFrom route={movement} />
                                    </dd>
                                </div>
                                <div>
                                    <dt>To</dt>
                                    <dd>{movement.to}</dd>
                                </div>
                                <div>
                                    <dt>Date {actualIncoming ? 'received' : 'sent'}</dt>
                                    <dd>{date || 'Not recorded'}</dd>
                                </div>
                                <div>
                                    <dt>Reference No.</dt>
                                    <dd>{mail.correspondence_reference || 'Not provided'}</dd>
                                </div>
                                <div className="full">
                                    <dt>Subject</dt>
                                    <dd>{mail.subject}</dd>
                                </div>
                                <div className="full">
                                    <dt>Details</dt>
                                    <dd>{mail.details || 'Not provided'}</dd>
                                </div>
                            </dl>
                        </div>
                    )}
                    <div className="mail-context">
                        ATS register number: {mail.register_number} · {mail.status} · {mail.office_name}
                    </div>
                    {section === 'details' && !editing && (
                        <>
                            <details className="basic-extras">
                                <summary>Attachments and additional information ({mail.attachments.length} files)</summary>
                                <div className="card pad">
                                    <dl className="detail-grid">
                                        <div>
                                            <dt>Priority</dt>
                                            <dd>{mail.priority}</dd>
                                        </div>
                                        <div>
                                            <dt>Confidentiality</dt>
                                            <dd>{mail.confidentiality}</dd>
                                        </div>
                                        <div>
                                            <dt>Receipt method</dt>
                                            <dd>{mail.receipt_method || 'Not recorded'}</dd>
                                        </div>
                                        <div>
                                            <dt>Captured by</dt>
                                            <dd>{mail.captured_by}</dd>
                                        </div>
                                    </dl>
                                    <ul className="attachment-list">
                                        {mail.attachments.map((attachment) => (
                                            <li key={attachment.id}>
                                                {attachment.filename} ·{' '}
                                                {attachment.preview_url && (
                                                    <a href={attachment.preview_url} target="_blank" rel="noreferrer">
                                                        Preview
                                                    </a>
                                                )}{' '}
                                                · <a href={attachment.download_url}>Download</a>
                                            </li>
                                        ))}
                                    </ul>
                                    {mail.attachments.length === 0 && <p>No attachments.</p>}
                                </div>
                            </details>
                            <MailStatusForm mail={mail} />
                            <div className="acts basic-workflow">
                                {mail.can_file && (
                                    <button className="btn" onClick={() => onWorkflow('file')}>
                                        File correspondence
                                    </button>
                                )}
                                {mail.can_reopen && (
                                    <button className="btn" onClick={() => onWorkflow('reopen')}>
                                        Reopen
                                    </button>
                                )}
                                <a className="btn" href={route('mail.print', mail.id)} target="_blank" rel="noreferrer">
                                    Print full record
                                </a>
                            </div>
                        </>
                    )}
                </>
            )}
        </>
    );
}
