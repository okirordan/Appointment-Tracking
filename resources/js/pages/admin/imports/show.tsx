import AppShell from '@/components/ats/app-shell';
import { AlertTriangle, ArrowLeft, CheckCircle2, Database, FileCheck2, FileSpreadsheet, Rows3 } from '@/components/icons';
import { Link, router } from '@inertiajs/react';

interface Batch {
    id: number;
    source_system: string;
    entity_type: string;
    status: string;
    original_filename: string;
    total_rows: number;
    valid_rows: number;
    failed_rows: number;
    created_rows: number;
    updated_rows: number;
}

interface PreviewRow {
    id: number;
    row_number: number;
    status: string;
    normalized_json: Record<string, unknown>;
    issues_json: { message: string }[] | null;
}

const hiddenPreviewFields = ['description', 'initial_instruction'];

export default function ImportShow({ batch, rows }: { batch: Batch; rows: { data: PreviewRow[] } }) {
    const confirm = () => {
        if (window.confirm(`Import ${batch.valid_rows.toLocaleString()} validated rows into the live system?`)) {
            router.post(route('admin.imports.confirm', batch.id));
        }
    };

    return (
        <AppShell title="Import Preview">
            <nav className="breadcrumbs" aria-label="Breadcrumb">
                <Link href={route('admin.imports.index')}>
                    <ArrowLeft aria-hidden="true" /> Data imports
                </Link>
                <span>/</span>
                <span>Batch #{batch.id}</span>
            </nav>

            <div className="page-hd import-preview-heading">
                <div>
                    <div className="eyebrow-label">Validation preview</div>
                    <h1>{batch.original_filename}</h1>
                    <div className="page-sub">
                        {batch.source_system} · {batch.entity_type.replaceAll('_', ' ')}
                    </div>
                </div>
                <div className="import-preview-actions">
                    <span className={`badge import-status status-${batch.status}`}>{batch.status.replaceAll('_', ' ')}</span>
                    {batch.status === 'ready' && (
                        <button className="btn btn-primary" onClick={confirm}>
                            <FileCheck2 aria-hidden="true" /> Confirm import
                        </button>
                    )}
                </div>
            </div>

            <div className="import-stat-grid">
                <ImportStat icon={<Rows3 />} tone="blue" label="Total rows" value={batch.total_rows} />
                <ImportStat icon={<CheckCircle2 />} tone="green" label="Valid rows" value={batch.valid_rows} />
                <ImportStat icon={<AlertTriangle />} tone="orange" label="Rows with issues" value={batch.failed_rows} />
                <ImportStat icon={<Database />} tone="purple" label="Database changes" value={batch.created_rows + batch.updated_rows} />
            </div>

            {batch.status === 'needs_attention' && (
                <div className="notice notice-danger import-validation-notice">
                    <AlertTriangle aria-hidden="true" />
                    <div>
                        <strong>Fix the spreadsheet before confirmation</strong>
                        <span>Download the template, correct the rows listed below, then stage a new file.</span>
                    </div>
                </div>
            )}

            {batch.status === 'completed' && (
                <div className="card import-result">
                    <span className="feature-icon feature-icon-green">
                        <CheckCircle2 aria-hidden="true" />
                    </span>
                    <div>
                        <h3>Import completed</h3>
                        <p>
                            {batch.created_rows.toLocaleString()} created · {batch.updated_rows.toLocaleString()} updated. The result is recorded in
                            the audit log.
                        </p>
                    </div>
                </div>
            )}

            <section className="card import-preview-card">
                <div className="import-card-heading">
                    <span className="feature-icon feature-icon-blue">
                        <FileSpreadsheet aria-hidden="true" />
                    </span>
                    <div>
                        <h3>Row preview</h3>
                        <p>Showing normalized values exactly as they will be written.</p>
                    </div>
                </div>
                <div className="table-scroll">
                    <table className="tbl import-preview-table">
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Status</th>
                                <th>Mapped values</th>
                                <th>Issues</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.data.map((row) => (
                                <tr key={row.id} className={row.status === 'invalid' ? 'invalid-row' : undefined}>
                                    <td>
                                        <strong>#{row.row_number}</strong>
                                    </td>
                                    <td>
                                        <span className={`badge ${row.status === 'invalid' ? 'pr-urgent' : 'st-completed'}`}>{row.status}</span>
                                    </td>
                                    <td>
                                        <div className="import-mapped-values">
                                            {Object.entries(row.normalized_json)
                                                .filter(([key, value]) => !hiddenPreviewFields.includes(key) && value !== null && value !== '')
                                                .map(([key, value]) => (
                                                    <span key={key}>
                                                        <strong>{key.replaceAll('_', ' ')}</strong>
                                                        {String(value)}
                                                    </span>
                                                ))}
                                        </div>
                                    </td>
                                    <td>
                                        {row.issues_json?.length ? (
                                            <ul className="import-issue-list">
                                                {row.issues_json.map((item, index) => (
                                                    <li key={`${row.id}-${index}`}>{item.message}</li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <span className="validated-label">
                                                <CheckCircle2 aria-hidden="true" /> Ready
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="card import-guidance-card">
                <h3>Rollback and recurring-import guidance</h3>
                <p>
                    A failed confirmation is automatically rolled back. For recurring files, keep the same source name and include stable external IDs
                    so existing records update instead of creating replacements.
                </p>
            </section>
        </AppShell>
    );
}

function ImportStat({ icon, tone, label, value }: { icon: React.ReactNode; tone: string; label: string; value: number }) {
    return (
        <div className="import-stat">
            <span className={`feature-icon feature-icon-${tone}`}>{icon}</span>
            <div>
                <span>{label}</span>
                <strong>{value.toLocaleString()}</strong>
            </div>
        </div>
    );
}
