import AppShell from '@/components/ats/app-shell';
import FormErrorSummary from '@/components/ats/form-error-summary';
import { Link, useForm } from '@inertiajs/react';
import { CheckCircle2, Download, FileSpreadsheet, History, ShieldCheck, UploadCloud } from 'lucide-react';

interface ImportBatchRow {
    id: number;
    source_system: string;
    entity_type: string;
    status: string;
    original_filename: string;
    total_rows: number;
    created_at: string;
}

interface Props {
    batches: { data: ImportBatchRow[] };
    entities: string[];
    entityLabels: Record<string, string>;
}

const mailEntities = ['incoming_mail', 'outgoing_mail'];

const columnGuidance: Record<string, string> = {
    incoming_mail: 'Required: FROM, TO, SUBJECT, DATE RECEIVED. Optional: REF NO, DETAILS and the additional template columns.',
    outgoing_mail: 'Required: FROM, TO, SUBJECT, DATE SENT. Optional: REF NO, DETAILS and the additional template columns.',
};

export default function ImportsIndex({ batches, entities, entityLabels }: Props) {
    const initialEntity = entities.includes('incoming_mail') ? 'incoming_mail' : (entities[0] ?? 'incoming_mail');
    const form = useForm<{ source_system: string; entity_type: string; file: File | null }>({
        source_system: '',
        entity_type: initialEntity,
        file: null,
    });
    const selectedFile = form.data.file;
    const isMailImport = mailEntities.includes(form.data.entity_type);

    return (
        <AppShell title="Data Imports">
            <div className="page-hd import-page-heading">
                <div>
                    <div className="eyebrow-label">System administration</div>
                    <h1>Excel data imports</h1>
                    <div className="page-sub">Validate spreadsheet rows first, review every issue, then commit the batch transactionally.</div>
                </div>
                <div className="import-heading-icon" aria-hidden="true">
                    <FileSpreadsheet />
                </div>
            </div>

            <div className="import-layout">
                <form
                    className="card import-upload-card"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(route('admin.imports.store'), { forceFormData: true });
                    }}
                >
                    <div className="import-card-heading">
                        <span className="feature-icon feature-icon-blue">
                            <UploadCloud aria-hidden="true" />
                        </span>
                        <div>
                            <h3>Upload and validate</h3>
                            <p>Excel (.xlsx) is recommended. CSV is also supported.</p>
                        </div>
                    </div>
                    <FormErrorSummary errors={form.errors} />

                    <div className="field">
                        <label htmlFor="im-source">Source or register name</label>
                        <input
                            id="im-source"
                            value={form.data.source_system}
                            onChange={(event) => form.setData('source_system', event.target.value)}
                            placeholder={isMailImport ? 'e.g. MOES Mail Register 2026' : 'e.g. Legacy Access 2026'}
                            required
                        />
                        <div className="field-help">Use the same name on recurring uploads so stable external IDs update existing records.</div>
                    </div>

                    <div className="field">
                        <label htmlFor="im-entity">Data type</label>
                        <select id="im-entity" value={form.data.entity_type} onChange={(event) => form.setData('entity_type', event.target.value)}>
                            <optgroup label="Mail registers">
                                {entities
                                    .filter((item) => mailEntities.includes(item))
                                    .map((item) => (
                                        <option key={item} value={item}>
                                            {entityLabels[item] ?? item.replaceAll('_', ' ')}
                                        </option>
                                    ))}
                            </optgroup>
                            <optgroup label="Administration and assignments">
                                {entities
                                    .filter((item) => !mailEntities.includes(item))
                                    .map((item) => (
                                        <option key={item} value={item}>
                                            {entityLabels[item] ?? item.replaceAll('_', ' ')}
                                        </option>
                                    ))}
                            </optgroup>
                        </select>
                    </div>

                    <div className="field">
                        <label htmlFor="im-file">Spreadsheet file</label>
                        <label className={`import-dropzone${selectedFile ? 'has-file' : ''}`} htmlFor="im-file">
                            <input
                                id="im-file"
                                type="file"
                                accept=".csv,.xlsx"
                                onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                                required
                            />
                            <FileSpreadsheet aria-hidden="true" />
                            <span>
                                <strong>{selectedFile ? selectedFile.name : 'Choose an Excel or CSV file'}</strong>
                                <small>
                                    {selectedFile ? `${(selectedFile.size / 1024 / 1024).toFixed(2)} MB selected` : 'Maximum file size: 20 MB'}
                                </small>
                            </span>
                        </label>
                    </div>

                    {columnGuidance[form.data.entity_type] && <div className="import-column-note">{columnGuidance[form.data.entity_type]}</div>}

                    <div className="import-actions">
                        <a className="btn btn-ghost" href={route('admin.imports.template', { entity: form.data.entity_type })}>
                            <Download aria-hidden="true" /> Download Excel template
                        </a>
                        <button className="btn btn-primary" disabled={form.processing || !selectedFile} type="submit">
                            <UploadCloud aria-hidden="true" /> {form.processing ? 'Validating file…' : 'Stage and validate'}
                        </button>
                    </div>
                </form>

                <aside className="card import-safety-card">
                    <div className="import-card-heading">
                        <span className="feature-icon feature-icon-green">
                            <ShieldCheck aria-hidden="true" />
                        </span>
                        <div>
                            <h3>Safe by design</h3>
                            <p>Uploading alone never changes the live registers.</p>
                        </div>
                    </div>
                    <ol className="workflow-list">
                        <li>
                            <strong>Private staging</strong>
                            <span>The file is stored away from operational attachments.</span>
                        </li>
                        <li>
                            <strong>Column and row validation</strong>
                            <span>Required fields, dates, lengths and allowed values are checked.</span>
                        </li>
                        <li>
                            <strong>Human confirmation</strong>
                            <span>A system administrator reviews the preview before import.</span>
                        </li>
                        <li>
                            <strong>Transactional commit</strong>
                            <span>If any database write fails, the entire confirmation rolls back.</span>
                        </li>
                        <li>
                            <strong>Audited result</strong>
                            <span>Created and updated totals are attached to the administrator’s audit trail.</span>
                        </li>
                    </ol>
                    <div className="import-tip">
                        <CheckCircle2 aria-hidden="true" />
                        <span>
                            Include an <strong>EXTERNAL ID</strong> column when available. It enables clean updates on future exports.
                        </span>
                    </div>
                </aside>
            </div>

            <section className="card import-history-card">
                <div className="import-card-heading">
                    <span className="feature-icon feature-icon-purple">
                        <History aria-hidden="true" />
                    </span>
                    <div>
                        <h3>Import history</h3>
                        <p>Open a batch to review its validation and completion result.</p>
                    </div>
                </div>
                <div className="table-scroll">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Data type</th>
                                <th>File</th>
                                <th>Rows</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {batches.data.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="empty-table-cell">
                                        No imports have been staged yet.
                                    </td>
                                </tr>
                            ) : (
                                batches.data.map((batch) => (
                                    <tr key={batch.id}>
                                        <td>
                                            <Link className="import-batch-link" href={route('admin.imports.show', batch.id)}>
                                                {batch.source_system}
                                            </Link>
                                        </td>
                                        <td>{entityLabels[batch.entity_type] ?? batch.entity_type.replaceAll('_', ' ')}</td>
                                        <td>{batch.original_filename}</td>
                                        <td>{batch.total_rows.toLocaleString()}</td>
                                        <td>
                                            <span className={`badge import-status status-${batch.status}`}>{batch.status.replaceAll('_', ' ')}</span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </section>
        </AppShell>
    );
}
