import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import { useConfirm } from '@/hooks/use-confirm';
import { router, useForm } from '@inertiajs/react';
import { Check, Pencil, Plus, ShieldCheck, Users } from 'lucide-react';
import { useState } from 'react';

interface RoleRow {
    id: number;
    name: string;
    display_name: string;
    description: string | null;
    hierarchy_level: number | null;
    is_active: boolean;
    is_system: boolean;
    active_users_count: number;
    positions_count: number;
    permission_names: string[];
}

interface Props {
    roles: RoleRow[];
    permissionGroups: Record<string, Array<{ name: string; description: string | null }>>;
}

export default function RolesIndex({ roles, permissionGroups }: Props) {
    const [editing, setEditing] = useState<RoleRow | 'new' | null>(null);
    const confirm = useConfirm();
    const toggle = async (role: RoleRow) => {
        const ok = await confirm({ title: `${role.is_active ? 'Deactivate' : 'Activate'} ${role.display_name}?`, message: role.is_active ? 'A role with active users cannot be deactivated. Existing history will remain unchanged.' : 'The role will become available for new appointments and accounts.', confirmLabel: role.is_active ? 'Deactivate role' : 'Activate role', variant: role.is_active ? 'danger' : 'default' });
        if (ok) router.post(route('admin.roles.toggle', role.id), {}, { preserveScroll: true });
    };

    return <AppShell title="Roles & Permissions">
        <div className="page-hd"><div><h1>Roles & Permissions</h1><div className="page-sub">Configure access and hierarchy without code changes</div></div><button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Plus aria-hidden="true" /> Add role</button></div>
        <div className="card"><div style={{ overflowX: 'auto' }}><table className="tbl"><thead><tr><th>Role</th><th>Hierarchy</th><th>Permissions</th><th>Usage</th><th>Status</th><th /></tr></thead><tbody>{roles.map((role) => <tr key={role.id}><td><div style={{ fontWeight: 700 }}>{role.display_name}</div><div style={{ color: 'var(--label)', fontSize: 12 }}>{role.description || role.name}{role.is_system ? ' · Built-in' : ''}</div></td><td>Level {role.hierarchy_level ?? '—'}</td><td><span className="badge st-received"><ShieldCheck aria-hidden="true" /> {role.permission_names.length}</span></td><td><span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><Users size={15} /> {role.active_users_count} users · {role.positions_count} positions</span></td><td><span className={`badge ${role.is_active ? 'st-completed' : 'st-archived'}`}>{role.is_active ? 'Active' : 'Inactive'}</span></td><td><div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}><button type="button" className="btn btn-ghost" style={{ padding: '6px 12px' }} onClick={() => setEditing(role)}><Pencil aria-hidden="true" /> Edit</button><button type="button" className="btn btn-ghost" style={{ padding: '6px 12px' }} onClick={() => toggle(role)}>{role.is_active ? 'Deactivate' : 'Activate'}</button></div></td></tr>)}</tbody></table></div>{roles.length === 0 && <EmptyState>No roles configured</EmptyState>}</div>
        {editing && <RoleModal role={editing === 'new' ? null : editing} permissionGroups={permissionGroups} onClose={() => setEditing(null)} />}
    </AppShell>;
}

function RoleModal({ role, permissionGroups, onClose }: { role: RoleRow | null; permissionGroups: Props['permissionGroups']; onClose: () => void }) {
    const form = useForm({ name: role?.display_name ?? '', description: role?.description ?? '', hierarchy_level: String(role?.hierarchy_level ?? 100), permissions: role?.permission_names ?? [], reason: '' });
    const setPermission = (name: string, enabled: boolean) => form.setData('permissions', enabled ? [...form.data.permissions, name] : form.data.permissions.filter((item) => item !== name));
    const submit = () => role ? form.put(route('admin.roles.update', role.id), { preserveScroll: true, onSuccess: onClose }) : form.post(route('admin.roles.store'), { preserveScroll: true, onSuccess: onClose });
    return <Modal title={role ? `Edit ${role.display_name}` : 'Create role'} onClose={onClose} footer={<><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button type="button" className="btn btn-primary" disabled={form.processing} onClick={submit}><Check aria-hidden="true" /> {role ? 'Save changes' : 'Create role'}</button></>}>
        <FormErrorSummary errors={form.errors} />
        <div className="two-col"><div className="field"><label htmlFor="role-name">Role name *</label><input id="role-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />{form.errors.name && <div className="field-error">{form.errors.name}</div>}</div><div className="field"><label htmlFor="role-level">Hierarchy level *</label><input id="role-level" type="number" min="0" value={form.data.hierarchy_level} onChange={(e) => form.setData('hierarchy_level', e.target.value)} /><span className="field-help">Lower numbers are more senior. Equal levels are allowed.</span></div></div>
        <div className="field"><label htmlFor="role-description">Description</label><textarea id="role-description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></div>
        <div className="section-title" style={{ marginTop: 18 }}><ShieldCheck aria-hidden="true" /> Permissions</div>
        <div style={{ display: 'grid', gap: 14 }}>{Object.entries(permissionGroups).map(([group, permissions]) => <fieldset key={group} className="card" style={{ padding: 14, boxShadow: 'none' }}><legend style={{ padding: '0 6px', fontWeight: 700, textTransform: 'capitalize' }}>{group}</legend><div style={{ display: 'grid', gap: 10 }}>{permissions.map((permission) => <label key={permission.name} style={{ display: 'grid', gridTemplateColumns: '18px 1fr', gap: 9, alignItems: 'start' }}><input type="checkbox" checked={form.data.permissions.includes(permission.name)} onChange={(e) => setPermission(permission.name, e.target.checked)} /><span><strong style={{ display: 'block' }}>{permission.name}</strong><small style={{ color: 'var(--label)' }}>{permission.description}</small></span></label>)}</div></fieldset>)}</div>
        {role && <div className="field" style={{ marginTop: 16 }}><label htmlFor="role-reason">Reason for change</label><textarea id="role-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /></div>}
    </Modal>;
}
