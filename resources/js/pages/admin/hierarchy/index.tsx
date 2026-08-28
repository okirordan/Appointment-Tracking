import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import { Building2, CalendarClock, Check, Edit3, Network, Plus, ShieldCheck, Trash2, UserRoundCheck } from '@/components/icons';
import { useConfirm } from '@/hooks/use-confirm';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Unit {
    id: number;
    name: string;
    code: string | null;
    type: string;
    parent_id: number | null;
    parent_name: string | null;
    department_id: number | null;
    department_name: string | null;
    division_id: number | null;
    division_name: string | null;
    active: boolean;
    positions_count: number;
    users_count: number;
}
interface Position {
    id: number;
    title: string;
    hierarchy_level: number;
    organizational_unit_id: number | null;
    unit_name: string;
    role_id: number;
    role_name: string;
    supervisor_position_id: number | null;
    supervisor_position_name: string | null;
    capabilities: string[];
    active: boolean;
    active_users_count: number;
}
interface UserOption {
    id: number;
    name: string;
    title: string | null;
    department_name: string | null;
    active_task_count: number;
}
interface Props {
    units: Unit[];
    departments: Array<{ id: number; name: string }>;
    divisions: Array<{ id: number; name: string; department_id: number }>;
    positions: Position[];
    appointments: Array<{
        id: number;
        user_id: number;
        user_name: string;
        user_inactive: boolean;
        position_id: number;
        position_name: string;
        unit_name: string | null;
        supervisor_user_id: number | null;
        supervisor_name: string | null;
        is_acting: boolean;
        starts_at: string | null;
        ends_at: string | null;
    }>;
    delegations: Array<{
        id: number;
        delegator_name: string;
        delegate_name: string;
        unit_name: string;
        starts_at: string;
        ends_at: string;
        reason: string;
    }>;
    secretaryAttachments: Array<{
        id: number;
        secretary_name: string;
        official_job_title: string;
        supervisor_name: string;
        supervisor_title: string | null;
        office_name: string | null;
        starts_at: string;
        ends_at: string | null;
        delegated_permissions: string[];
    }>;
    secretaryPermissionOptions: Array<{ value: string; label: string }>;
    roles: Array<{ id: number; name: string; hierarchy_level: number | null }>;
    users: UserOption[];
}

type Dialog = 'unit' | 'position' | 'appointment' | 'delegation' | 'secretary' | null;

export default function HierarchyIndex(props: Props) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const [editingUnit, setEditingUnit] = useState<Unit | null>(null);
    const confirm = useConfirm();
    const removeSecretary = async (item: Props['secretaryAttachments'][number]) => {
        const approved = await confirm({
            title: `Remove ${item.secretary_name} from this office?`,
            message: 'Their shared-office access ends immediately. Correspondence, assignments and audit history remain unchanged.',
            confirmLabel: 'Remove access',
            variant: 'danger',
        });
        if (approved) {
            router.delete(route('admin.hierarchy.secretary-attachments.destroy', item.id), {
                data: { reason: 'Office access removed by an administrator.' },
                preserveScroll: true,
            });
        }
    };
    return (
        <AppShell title="Organization Hierarchy">
            <div className="page-hd">
                <div>
                    <h1>Organization Hierarchy</h1>
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <Action
                        label="Add unit"
                        onClick={() => {
                            setEditingUnit(null);
                            setDialog('unit');
                        }}
                    />
                    <Action label="Add position" onClick={() => setDialog('position')} />
                    <Action label="Assign user" onClick={() => setDialog('appointment')} primary />
                    <Action label="Attach secretary" onClick={() => setDialog('secretary')} />
                    <Action label="Temporary delegation" onClick={() => setDialog('delegation')} />
                </div>
            </div>
            <div className="two-col" style={{ alignItems: 'start' }}>
                <Section title="Organizational units" icon={<Building2 aria-hidden="true" />} empty="No organizational units configured">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Unit</th>
                                <th>Type</th>
                                <th>Department / parent</th>
                                <th>Positions</th>
                                <th>Officers</th>
                                <th>
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {props.units.map((unit) => (
                                <tr key={unit.id}>
                                    <td>
                                        <strong>{unit.name}</strong>
                                        <div style={{ color: 'var(--label)', fontSize: 12 }}>{unit.code ?? 'No code'}</div>
                                    </td>
                                    <td style={{ textTransform: 'capitalize' }}>{unit.type}</td>
                                    <td>
                                        {unit.department_name ?? 'Standalone — attach later'}
                                        {unit.division_name && <div style={{ color: 'var(--label)', fontSize: 12 }}>{unit.division_name}</div>}
                                        {unit.parent_name && <div style={{ color: 'var(--label)', fontSize: 12 }}>Parent: {unit.parent_name}</div>}
                                    </td>
                                    <td>{unit.positions_count}</td>
                                    <td>{unit.users_count}</td>
                                    <td>
                                        <button
                                            type="button"
                                            className="btn btn-ghost"
                                            onClick={() => {
                                                setEditingUnit(unit);
                                                setDialog('unit');
                                            }}
                                        >
                                            <Edit3 aria-hidden="true" /> Edit
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Section>
                <Section title="Positions and reporting route" icon={<Network aria-hidden="true" />} empty="No positions configured">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Position</th>
                                <th>Level</th>
                                <th>Reports to</th>
                                <th>Users</th>
                            </tr>
                        </thead>
                        <tbody>
                            {props.positions.map((position) => (
                                <tr key={position.id}>
                                    <td>
                                        <strong>{position.title}</strong>
                                        <div style={{ color: 'var(--label)', fontSize: 12 }}>
                                            {position.role_name} · {position.unit_name}
                                        </div>
                                    </td>
                                    <td>{position.hierarchy_level}</td>
                                    <td>{position.supervisor_position_name ?? 'Top level'}</td>
                                    <td>{position.active_users_count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Section>
            </div>
            <div className="two-col" style={{ alignItems: 'start', marginTop: 18 }}>
                <Section title="Current appointments" icon={<UserRoundCheck aria-hidden="true" />} empty="No user positions configured">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Position</th>
                                <th>Supervisor</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            {props.appointments.map((item) => (
                                <tr key={item.id}>
                                    <td>
                                        {item.user_name}
                                        {item.user_inactive && (
                                            <span className="badge pr-urgent" style={{ marginLeft: 6 }}>
                                                Inactive
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <strong>{item.position_name}</strong>
                                        <div style={{ color: 'var(--label)', fontSize: 12 }}>{item.unit_name}</div>
                                    </td>
                                    <td>{item.supervisor_name ?? 'From position'}</td>
                                    <td>{item.is_acting ? 'Acting' : 'Substantive'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Section>
                <Section title="Active temporary delegations" icon={<CalendarClock aria-hidden="true" />} empty="No temporary delegations active">
                    <div style={{ display: 'grid', gap: 10 }}>
                        {props.delegations.map((item) => (
                            <article key={item.id} className="card" style={{ padding: 14, boxShadow: 'none' }}>
                                <strong>
                                    {item.delegator_name} → {item.delegate_name}
                                </strong>
                                <div style={{ color: 'var(--label)', marginTop: 4 }}>
                                    {item.unit_name} · {item.starts_at} to {item.ends_at}
                                </div>
                                <p style={{ marginTop: 7 }}>{item.reason}</p>
                            </article>
                        ))}
                    </div>
                </Section>
            </div>
            <div style={{ marginTop: 18 }}>
                <Section
                    title="Secretary office attachments"
                    icon={<ShieldCheck aria-hidden="true" />}
                    empty="No secretaries are attached to an office"
                >
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Secretary</th>
                                <th>Supported office</th>
                                <th>Supervisor</th>
                                <th>Period</th>
                                <th>Delegated authority</th>
                                <th>
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {props.secretaryAttachments.map((item) => (
                                <tr key={item.id}>
                                    <td>
                                        <strong>{item.secretary_name}</strong>
                                        <div style={{ color: 'var(--label)', fontSize: 12 }}>{item.official_job_title}</div>
                                    </td>
                                    <td>{item.office_name ?? 'Supervisor office'}</td>
                                    <td>
                                        <strong>{item.supervisor_name}</strong>
                                        <div style={{ color: 'var(--label)', fontSize: 12 }}>{item.supervisor_title}</div>
                                    </td>
                                    <td>
                                        {item.starts_at} — {item.ends_at ?? 'Current'}
                                    </td>
                                    <td>{item.delegated_permissions.length === 0 ? 'None' : item.delegated_permissions.join(', ')}</td>
                                    <td>
                                        <button
                                            type="button"
                                            className="btn btn-ghost"
                                            aria-label={`Remove ${item.secretary_name} from this office`}
                                            onClick={() => void removeSecretary(item)}
                                        >
                                            <Trash2 aria-hidden="true" /> Remove
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Section>
            </div>
            {dialog === 'unit' && (
                <UnitModal
                    unit={editingUnit}
                    units={props.units}
                    departments={props.departments}
                    divisions={props.divisions}
                    onClose={() => {
                        setEditingUnit(null);
                        setDialog(null);
                    }}
                />
            )}
            {dialog === 'position' && <PositionModal {...props} onClose={() => setDialog(null)} />}
            {dialog === 'appointment' && <AppointmentModal {...props} onClose={() => setDialog(null)} />}
            {dialog === 'delegation' && <DelegationModal {...props} onClose={() => setDialog(null)} />}
            {dialog === 'secretary' && <SecretaryAttachmentModal {...props} onClose={() => setDialog(null)} />}
        </AppShell>
    );
}

function Action({ label, onClick, primary = false }: { label: string; onClick: () => void; primary?: boolean }) {
    return (
        <button type="button" className={`btn ${primary ? 'btn-primary' : 'btn-ghost'}`} onClick={onClick}>
            <Plus aria-hidden="true" /> {label}
        </button>
    );
}
function Section({ title, icon, empty, children }: { title: string; icon: React.ReactNode; empty: string; children: React.ReactNode }) {
    const hasRows = Array.isArray((children as React.ReactElement<{ children?: React.ReactNode }>)?.props?.children) ? true : true;
    return (
        <section className="card" style={{ padding: 18, overflowX: 'auto' }}>
            <div className="section-title">
                {icon}
                {title}
            </div>
            {hasRows ? children : <EmptyState>{empty}</EmptyState>}
        </section>
    );
}

function UnitModal({
    unit,
    units,
    departments,
    divisions,
    onClose,
}: {
    unit: Unit | null;
    units: Unit[];
    departments: Props['departments'];
    divisions: Props['divisions'];
    onClose: () => void;
}) {
    const form = useForm({
        name: unit?.name ?? '',
        code: unit?.code ?? '',
        type: unit?.type ?? 'unit',
        parent_id: unit?.parent_id ? String(unit.parent_id) : '',
        department_id: unit?.department_id ? String(unit.department_id) : '',
        division_id: unit?.division_id ? String(unit.division_id) : '',
        active: unit?.active ?? true,
        reason: '',
    });
    const save = () => {
        if (unit === null) {
            form.post(route('admin.hierarchy.units.store'), { onSuccess: onClose });
            return;
        }
        form.put(route('admin.hierarchy.units.update', unit.id), { onSuccess: onClose });
    };
    return (
        <Modal
            title={unit === null ? 'Add organizational unit' : `Edit ${unit.name}`}
            onClose={onClose}
            footer={<Footer processing={form.processing} onClose={onClose} onSave={save} />}
        >
            <FormErrorSummary errors={form.errors} />
            <div className="two-col">
                <Field label="Name *">
                    <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </Field>
                <Field label="Code">
                    <input value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                </Field>
            </div>
            <div className="two-col">
                <Field label="Type *">
                    <select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                        {['institution', 'directorate', 'department', 'division', 'section', 'unit'].map((item) => (
                            <option key={item}>{item}</option>
                        ))}
                    </select>
                </Field>
                <Field label="Parent unit">
                    <select value={form.data.parent_id} onChange={(e) => form.setData('parent_id', e.target.value)}>
                        <option value="">No parent unit</option>
                        {units
                            .filter((item) => item.id !== unit?.id)
                            .map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                    </select>
                </Field>
            </div>
            <div className="two-col">
                <Field label="Department">
                    <select
                        value={form.data.department_id}
                        onChange={(event) => form.setData((current) => ({ ...current, department_id: event.target.value, division_id: '' }))}
                    >
                        <option value="">Standalone — attach later</option>
                        {departments.map((department) => (
                            <option key={department.id} value={department.id}>
                                {department.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Division">
                    <select
                        value={form.data.division_id}
                        disabled={!form.data.department_id}
                        onChange={(event) => form.setData('division_id', event.target.value)}
                    >
                        <option value="">No division</option>
                        {divisions
                            .filter((division) => String(division.department_id) === form.data.department_id)
                            .map((division) => (
                                <option key={division.id} value={division.id}>
                                    {division.name}
                                </option>
                            ))}
                    </select>
                </Field>
            </div>
            {unit !== null && (
                <label className="check-row">
                    <input type="checkbox" checked={form.data.active} onChange={(event) => form.setData('active', event.target.checked)} />
                    Active and available for placements
                </label>
            )}
            <Field label="Reason for change">
                <textarea
                    value={form.data.reason}
                    placeholder="Recorded in the audit trail"
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
            </Field>
            <div className="field-help">
                A unit may remain standalone. Attaching it to a department later updates officers already placed in that unit while preserving their
                history.
            </div>
        </Modal>
    );
}

function PositionModal({ units, positions, roles, onClose }: Props & { onClose: () => void }) {
    const form = useForm({
        title: '',
        organizational_unit_id: '',
        role_id: '',
        supervisor_position_id: '',
        hierarchy_level: '100',
        workflow_capabilities: ['assign', 'review'] as string[],
    });
    const capabilities = ['assign', 'review', 'approve', 'reject', 'return', 'escalate'];
    return (
        <Modal
            title="Add position"
            onClose={onClose}
            footer={
                <Footer
                    processing={form.processing}
                    onClose={onClose}
                    onSave={() => form.post(route('admin.hierarchy.positions.store'), { onSuccess: onClose })}
                />
            }
        >
            <FormErrorSummary errors={form.errors} />
            <Field label="Position title *">
                <input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
            </Field>
            <div className="two-col">
                <Field label="Unit">
                    <select value={form.data.organizational_unit_id} onChange={(e) => form.setData('organizational_unit_id', e.target.value)}>
                        <option value="">Institution-wide</option>
                        {units.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Role *">
                    <select value={form.data.role_id} onChange={(e) => form.setData('role_id', e.target.value)}>
                        <option value="">Select role</option>
                        {roles.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>
            <div className="two-col">
                <Field label="Reports to position">
                    <select value={form.data.supervisor_position_id} onChange={(e) => form.setData('supervisor_position_id', e.target.value)}>
                        <option value="">Top level</option>
                        {positions.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.title} — {item.unit_name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Hierarchy level *">
                    <input
                        type="number"
                        min="0"
                        value={form.data.hierarchy_level}
                        onChange={(e) => form.setData('hierarchy_level', e.target.value)}
                    />
                </Field>
            </div>
            <div className="field">
                <label>Workflow capabilities</label>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
                    {capabilities.map((item) => (
                        <label key={item} className="badge st-received">
                            <input
                                type="checkbox"
                                checked={form.data.workflow_capabilities.includes(item)}
                                onChange={(e) =>
                                    form.setData(
                                        'workflow_capabilities',
                                        e.target.checked
                                            ? [...form.data.workflow_capabilities, item]
                                            : form.data.workflow_capabilities.filter((value) => value !== item),
                                    )
                                }
                            />{' '}
                            {item}
                        </label>
                    ))}
                </div>
            </div>
        </Modal>
    );
}

function AppointmentModal({ users, positions, onClose }: Props & { onClose: () => void }) {
    const form = useForm({
        user_id: '',
        position_id: '',
        supervisor_user_id: '',
        is_acting: false as boolean,
        acting_for_user_id: '',
        starts_at: '',
        ends_at: '',
        reason: '',
    });
    const selectedUser = users.find((item) => String(item.id) === form.data.user_id);
    return (
        <Modal
            title="Assign user to position"
            onClose={onClose}
            footer={
                <Footer
                    processing={form.processing}
                    onClose={onClose}
                    onSave={() => form.post(route('admin.hierarchy.appointments.store'), { onSuccess: onClose })}
                />
            }
        >
            <FormErrorSummary errors={form.errors} />
            <div className="two-col">
                <Field label="User *">
                    <select value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value)}>
                        <option value="">Select user</option>
                        {users.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                                {item.title ? ` — ${item.title}` : ''}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Position *">
                    <select value={form.data.position_id} onChange={(e) => form.setData('position_id', e.target.value)}>
                        <option value="">Select position</option>
                        {positions.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.title} — {item.unit_name}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>
            {selectedUser !== undefined && selectedUser.active_task_count > 0 && (
                <div className="notice notice-info" role="status" style={{ marginBottom: 14 }}>
                    <strong>
                        {selectedUser.name} has {selectedUser.active_task_count} active task{selectedUser.active_task_count === 1 ? '' : 's'}.
                    </strong>
                    <div>
                        These tasks will not transfer automatically. The assigning officer or authorised supervisor must review, reassign, unassign,
                        or close them.
                    </div>
                </div>
            )}
            <Field label="Direct supervisor">
                <select value={form.data.supervisor_user_id} onChange={(e) => form.setData('supervisor_user_id', e.target.value)}>
                    <option value="">Derive from position</option>
                    {users
                        .filter((item) => String(item.id) !== form.data.user_id)
                        .map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                </select>
            </Field>
            <label style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
                <input type="checkbox" checked={form.data.is_acting} onChange={(e) => form.setData('is_acting', e.target.checked)} /> Acting
                appointment
            </label>
            {form.data.is_acting && (
                <Field label="Acting for">
                    <select value={form.data.acting_for_user_id} onChange={(e) => form.setData('acting_for_user_id', e.target.value)}>
                        <option value="">Select substantive holder</option>
                        {users.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </Field>
            )}
            <div className="two-col">
                <Field label="Effective start">
                    <input type="datetime-local" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                </Field>
                <Field label="Effective end">
                    <input type="datetime-local" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                </Field>
            </div>
            <Field label="Reason / transfer note">
                <textarea
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="Record the appointment, transfer, promotion, or replacement reason."
                />
            </Field>
        </Modal>
    );
}

function DelegationModal({ users, units, onClose }: Props & { onClose: () => void }) {
    const form = useForm({ delegator_user_id: '', delegate_user_id: '', organizational_unit_id: '', starts_at: '', ends_at: '', reason: '' });
    return (
        <Modal
            title="Temporary delegation"
            onClose={onClose}
            footer={
                <Footer
                    processing={form.processing}
                    onClose={onClose}
                    onSave={() => form.post(route('admin.hierarchy.delegations.store'), { onSuccess: onClose })}
                />
            }
        >
            <FormErrorSummary errors={form.errors} />
            <div className="two-col">
                <Field label="Delegator *">
                    <select value={form.data.delegator_user_id} onChange={(e) => form.setData('delegator_user_id', e.target.value)}>
                        <option value="">Select user</option>
                        {users.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Delegate *">
                    <select value={form.data.delegate_user_id} onChange={(e) => form.setData('delegate_user_id', e.target.value)}>
                        <option value="">Select replacement</option>
                        {users
                            .filter((item) => String(item.id) !== form.data.delegator_user_id)
                            .map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                    </select>
                </Field>
            </div>
            <Field label="Scope">
                <select value={form.data.organizational_unit_id} onChange={(e) => form.setData('organizational_unit_id', e.target.value)}>
                    <option value="">All authorized work</option>
                    {units.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </Field>
            <div className="two-col">
                <Field label="Starts *">
                    <input type="datetime-local" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                </Field>
                <Field label="Ends *">
                    <input type="datetime-local" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                </Field>
            </div>
            <Field label="Reason *">
                <textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
            </Field>
        </Modal>
    );
}

function SecretaryAttachmentModal({ users, units, secretaryPermissionOptions, onClose }: Props & { onClose: () => void }) {
    const form = useForm({
        secretary_user_id: '',
        supervisor_user_id: '',
        organizational_unit_id: '',
        official_job_title: 'Senior Personal Secretary',
        starts_at: new Date().toISOString().slice(0, 16),
        ends_at: '',
        delegated_actions_permitted: false as boolean,
        delegated_permissions: [] as string[],
        reason: '',
    });
    const secretaryCandidates = users.filter((item) => item.title?.toLowerCase().includes('secretary'));
    return (
        <Modal
            title="Attach secretary to an office"
            onClose={onClose}
            footer={
                <Footer
                    processing={form.processing}
                    onClose={onClose}
                    onSave={() => form.post(route('admin.hierarchy.secretary-attachments.store'), { onSuccess: onClose })}
                />
            }
        >
            <FormErrorSummary errors={form.errors} />
            <div className="notice notice-info" style={{ marginBottom: 16 }}>
                The secretary keeps a separate system role. This attachment controls dashboard scope; only explicitly selected authority is delegated.
            </div>
            <div className="two-col">
                <Field label="Secretary *">
                    <select value={form.data.secretary_user_id} onChange={(event) => form.setData('secretary_user_id', event.target.value)}>
                        <option value="">Select secretary</option>
                        {secretaryCandidates.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name} — {item.title}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Immediate supervisor *">
                    <select value={form.data.supervisor_user_id} onChange={(event) => form.setData('supervisor_user_id', event.target.value)}>
                        <option value="">Select supported officer</option>
                        {users
                            .filter((item) => String(item.id) !== form.data.secretary_user_id)
                            .map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                    {item.title ? ` — ${item.title}` : ''}
                                </option>
                            ))}
                    </select>
                </Field>
            </div>
            <div className="two-col">
                <Field label="Official job title *">
                    <input value={form.data.official_job_title} onChange={(event) => form.setData('official_job_title', event.target.value)} />
                </Field>
                <Field label="Office or department supported">
                    <select value={form.data.organizational_unit_id} onChange={(event) => form.setData('organizational_unit_id', event.target.value)}>
                        <option value="">Use supervisor's office</option>
                        {units.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>
            <div className="two-col">
                <Field label="Effective start *">
                    <input type="datetime-local" value={form.data.starts_at} onChange={(event) => form.setData('starts_at', event.target.value)} />
                </Field>
                <Field label="Effective end">
                    <input type="datetime-local" value={form.data.ends_at} onChange={(event) => form.setData('ends_at', event.target.value)} />
                </Field>
            </div>
            <label className="workflow-direct-option" style={{ marginBottom: 12 }}>
                <input
                    type="checkbox"
                    checked={form.data.delegated_actions_permitted}
                    onChange={(event) =>
                        form.setData((current) => ({
                            ...current,
                            delegated_actions_permitted: event.target.checked,
                            delegated_permissions: event.target.checked ? current.delegated_permissions : [],
                        }))
                    }
                />
                <span>
                    <strong>Permit delegated actions</strong>
                    <small>Leave off for operational dashboard access without supervisor authority.</small>
                </span>
            </label>
            {form.data.delegated_actions_permitted && (
                <div className="field">
                    <label>Specific delegated permissions</label>
                    <div className="secretary-admin-permission-grid">
                        {secretaryPermissionOptions.map((permission) => (
                            <label key={permission.value}>
                                <input
                                    type="checkbox"
                                    checked={form.data.delegated_permissions.includes(permission.value)}
                                    onChange={(event) =>
                                        form.setData(
                                            'delegated_permissions',
                                            event.target.checked
                                                ? [...form.data.delegated_permissions, permission.value]
                                                : form.data.delegated_permissions.filter((value) => value !== permission.value),
                                        )
                                    }
                                />
                                <span>{permission.label}</span>
                            </label>
                        ))}
                    </div>
                </div>
            )}
            <Field label="Reason for attachment or reassignment *">
                <textarea value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
            </Field>
        </Modal>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="field">
            <label>{label}</label>
            {children}
        </div>
    );
}
function Footer({ processing, onClose, onSave }: { processing: boolean; onClose: () => void; onSave: () => void }) {
    return (
        <>
            <button type="button" className="btn btn-ghost" onClick={onClose}>
                Cancel
            </button>
            <button type="button" className="btn btn-primary" disabled={processing} onClick={onSave}>
                <Check aria-hidden="true" /> Save
            </button>
        </>
    );
}
