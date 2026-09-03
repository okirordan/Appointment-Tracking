import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import { ArrowLeft, Check, History, RotateCcw, Trash2, UserRound } from '@/components/icons';
import type { SelectOption } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import OrganizationEntitySelect, { type StaffOrganizationOption } from './organization-entity-select';

interface UserRecord {
    id: number;
    full_name: string;
    title: string | null;
    username: string;
    email: string | null;
    employee_number: string | null;
    role_id: number | null;
    role_label: string;
    supervisor_user_id: number | null;
    supervisor_name: string | null;
    position_id: number | null;
    organizational_unit_id: number | null;
    position_name: string | null;
    organization_path: string | null;
    supported_office_name: string | null;
    supported_supervisor_name: string | null;
    active: boolean;
    deleted: boolean;
    deletion_reason: string | null;
    deleted_at_label: string | null;
}

interface Props {
    userRecord: UserRecord;
    changes: Array<{
        id: number;
        field: string;
        old_value: string | null;
        new_value: string | null;
        changed_by: string;
        reason: string | null;
        when_label: string;
    }>;
    positionChanges: Array<{
        id: number;
        previous_title: string | null;
        new_title: string | null;
        previous_role: string | null;
        new_role: string | null;
        previous_position: string | null;
        new_position: string | null;
        effective_date_label: string;
        changed_by: string;
        changed_at_label: string;
        reason: string | null;
    }>;
    lifecycle: Array<{ id: number; event: string; performed_by: string; reason: string | null; when_label: string }>;
    roleOptions: Array<SelectOption & { name: string }>;
    organizationOptions: StaffOrganizationOption[];
    positionOptions: Array<{ id: number; title: string; organizational_unit_id: number | null; role_id: number }>;
    userOptions: Array<{ id: number; full_name: string; title: string | null }>;
    today: string;
}

export default function UserProfile({
    userRecord,
    changes,
    positionChanges,
    lifecycle,
    roleOptions,
    organizationOptions,
    positionOptions,
    userOptions,
    today,
}: Props) {
    const [lifecycleAction, setLifecycleAction] = useState<'delete' | 'restore' | null>(null);
    const form = useForm({
        username: userRecord.username,
        full_name: userRecord.full_name,
        title: userRecord.title ?? '',
        email: userRecord.email ?? '',
        employee_number: userRecord.employee_number ?? '',
        role_id: String(userRecord.role_id ?? ''),
        organizational_unit_id: userRecord.organizational_unit_id ? String(userRecord.organizational_unit_id) : '',
        position_id: userRecord.position_id ? String(userRecord.position_id) : '',
        supervisor_user_id: userRecord.supervisor_user_id ? String(userRecord.supervisor_user_id) : '',
        effective_date: today,
        reason: '',
    });
    const usesApprovedPosition = form.data.position_id !== '';
    const allowsUnassigned = roleOptions.find((option) => option.value === form.data.role_id)?.name === 'sysadmin';

    return (
        <AppShell title={`${userRecord.full_name} — User Profile`}>
            <div className="page-hd user-profile-header">
                <div>
                    <button
                        type="button"
                        className="btn btn-ghost"
                        style={{ marginBottom: 12 }}
                        onClick={() => router.get(route('admin.users.index'))}
                    >
                        <ArrowLeft aria-hidden="true" /> User management
                    </button>
                    <h1>{userRecord.full_name}</h1>
                    <p className="page-subtitle">Manage identity, role, reporting line and organizational access in one place.</p>
                </div>
                <span className={`badge ${userRecord.deleted ? 'pr-urgent' : userRecord.active ? 'st-completed' : 'st-archived'}`}>
                    {userRecord.deleted ? 'Deleted account' : userRecord.active ? 'Active account' : 'Inactive account'}
                </span>
            </div>

            <div className="user-profile-layout">
                <section className="card user-profile-form">
                    <div className="section-title">
                        <UserRound aria-hidden="true" /> Staff profile and access
                    </div>
                    <FormErrorSummary errors={form.errors} />
                    <div className="two-col">
                        <div className="field">
                            <label htmlFor="profile-name">Full name *</label>
                            <input
                                id="profile-name"
                                value={form.data.full_name}
                                disabled={userRecord.deleted}
                                onChange={(e) => form.setData('full_name', e.target.value)}
                            />
                        </div>
                        <div className="field">
                            <label htmlFor="profile-username">Login username *</label>
                            <input
                                id="profile-username"
                                autoComplete="username"
                                spellCheck={false}
                                value={form.data.username}
                                disabled={userRecord.deleted}
                                onChange={(e) => form.setData('username', e.target.value.toLowerCase())}
                            />
                            <div className="field-help">Changing this takes effect on the user’s next sign-in. Their password is unchanged.</div>
                        </div>
                    </div>
                    <div className="two-col">
                        <div className="field">
                            <label htmlFor="profile-title">Title / designation</label>
                            <input
                                id="profile-title"
                                value={form.data.title}
                                disabled={userRecord.deleted}
                                readOnly={usesApprovedPosition}
                                onChange={(e) => form.setData('title', e.target.value)}
                            />
                            {usesApprovedPosition && <div className="field-help">Set automatically from the approved position.</div>}
                        </div>
                        <div className="field">
                            <label htmlFor="profile-email">Email</label>
                            <input
                                id="profile-email"
                                type="email"
                                value={form.data.email}
                                disabled={userRecord.deleted}
                                onChange={(e) => form.setData('email', e.target.value)}
                            />
                        </div>
                    </div>
                    <div className="two-col">
                        <div className="field">
                            <label htmlFor="profile-employee">Staff ID</label>
                            <input
                                id="profile-employee"
                                value={form.data.employee_number}
                                disabled={userRecord.deleted}
                                onChange={(e) => form.setData('employee_number', e.target.value)}
                            />
                        </div>
                        <div className="field">
                            <label htmlFor="profile-role">Role *</label>
                            <select
                                id="profile-role"
                                value={form.data.role_id}
                                disabled={userRecord.deleted || usesApprovedPosition}
                                onChange={(e) => form.setData('role_id', e.target.value)}
                            >
                                {roleOptions.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <OrganizationEntitySelect
                        idPrefix="profile"
                        options={organizationOptions}
                        value={form.data.organizational_unit_id}
                        disabled={userRecord.deleted}
                        error={form.errors.organizational_unit_id}
                        allowUnassigned={allowsUnassigned}
                        onChange={(value) =>
                            form.setData((current) => ({
                                ...current,
                                organizational_unit_id: value,
                                position_id: '',
                            }))
                        }
                    />
                    <div className="two-col">
                        <div className="field">
                            <label htmlFor="profile-position">Approved position</label>
                            <select
                                id="profile-position"
                                value={form.data.position_id}
                                disabled={userRecord.deleted || form.data.organizational_unit_id === ''}
                                onChange={(e) => {
                                    const position = positionOptions.find((item) => String(item.id) === e.target.value);
                                    form.setData((current) => ({
                                        ...current,
                                        position_id: e.target.value,
                                        title: position?.title ?? current.title,
                                        role_id: position ? String(position.role_id) : current.role_id,
                                    }));
                                }}
                            >
                                <option value="">Use manually entered title and role</option>
                                {positionOptions
                                    .filter((item) => String(item.organizational_unit_id ?? '') === form.data.organizational_unit_id)
                                    .map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.title}
                                        </option>
                                    ))}
                            </select>
                            <div className="field-help">Selecting a position updates the exact title, dashboard and permissions together.</div>
                        </div>
                        <div className="field">
                            <label htmlFor="profile-supervisor">Direct supervisor</label>
                            <select
                                id="profile-supervisor"
                                value={form.data.supervisor_user_id}
                                disabled={userRecord.deleted}
                                onChange={(e) => form.setData('supervisor_user_id', e.target.value)}
                            >
                                <option value="">Not configured</option>
                                {userOptions.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.full_name}
                                        {item.title ? ` — ${item.title}` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="field">
                        <label htmlFor="profile-effective-date">Effective date *</label>
                        <input
                            id="profile-effective-date"
                            type="date"
                            value={form.data.effective_date}
                            disabled={userRecord.deleted}
                            onChange={(e) => form.setData('effective_date', e.target.value)}
                        />
                        <div className="field-help">Used in the permanent position-change history.</div>
                        <div className="field-help">
                            For a transfer, choose the new organizational entity, select an approved position when available, and record the effective
                            date and reason.
                        </div>
                    </div>
                    <div className="field">
                        <label htmlFor="profile-reason">Reason for change (recommended)</label>
                        <textarea
                            id="profile-reason"
                            value={form.data.reason}
                            disabled={userRecord.deleted}
                            placeholder="Recorded with every changed field"
                            onChange={(e) => form.setData('reason', e.target.value)}
                        />
                    </div>
                    {!userRecord.deleted && (
                        <button
                            type="button"
                            className="btn btn-primary"
                            disabled={form.processing}
                            onClick={() => form.put(route('admin.users.update', userRecord.id), { preserveScroll: true })}
                        >
                            <Check aria-hidden="true" /> Save profile
                        </button>
                    )}
                </section>

                <aside className="card user-profile-summary">
                    <div className="section-title">Current placement</div>
                    <div className="meta-grid">
                        <div>
                            <span>Username</span>
                            {userRecord.username}
                        </div>
                        <div>
                            <span>Role</span>
                            {userRecord.role_label}
                        </div>
                        <div>
                            <span>Position</span>
                            {userRecord.position_name ?? userRecord.title ?? '—'}
                        </div>
                        <div>
                            <span>Organizational entity</span>
                            {userRecord.organization_path ?? '—'}
                        </div>
                        <div>
                            <span>Supervisor</span>
                            {userRecord.supervisor_name ?? 'Not configured'}
                        </div>
                        {userRecord.supported_supervisor_name && (
                            <div>
                                <span>Supported supervisor</span>
                                {userRecord.supported_supervisor_name}
                            </div>
                        )}
                        {userRecord.supported_office_name && (
                            <div>
                                <span>Supported office</span>
                                {userRecord.supported_office_name}
                            </div>
                        )}
                    </div>
                    {userRecord.deleted && (
                        <div className="notice notice-danger" style={{ marginTop: 16 }}>
                            <strong>Deleted {userRecord.deleted_at_label}</strong>
                            <br />
                            {userRecord.deletion_reason}
                        </div>
                    )}
                    <div style={{ marginTop: 18 }}>
                        {userRecord.deleted ? (
                            <button type="button" className="btn btn-primary" onClick={() => setLifecycleAction('restore')}>
                                <RotateCcw aria-hidden="true" /> Restore account
                            </button>
                        ) : (
                            <button
                                type="button"
                                className="btn btn-ghost"
                                style={{ color: 'var(--err)' }}
                                onClick={() => setLifecycleAction('delete')}
                            >
                                <Trash2 aria-hidden="true" /> Delete account safely
                            </button>
                        )}
                    </div>
                </aside>
            </div>

            <div className="two-col user-profile-history-grid">
                <HistoryCard
                    title="Position and role history"
                    items={positionChanges.map((item) => ({
                        id: item.id,
                        title: `${item.previous_title ?? 'Unassigned'} → ${item.new_title ?? 'Unassigned'}`,
                        detail: `${item.previous_role ?? 'No system role'} → ${item.new_role ?? 'No system role'} · Effective ${item.effective_date_label}`,
                        meta: `${item.changed_by} · Changed ${item.changed_at_label}`,
                        reason: item.reason,
                    }))}
                />
                <HistoryCard
                    title="Profile change history"
                    items={changes.map((item) => ({
                        id: item.id,
                        title: item.field,
                        detail: `${item.old_value ?? 'Empty'} → ${item.new_value ?? 'Empty'}`,
                        meta: `${item.changed_by} · ${item.when_label}`,
                        reason: item.reason,
                    }))}
                />
            </div>
            <div className="user-profile-history-grid">
                <HistoryCard
                    title="Account lifecycle"
                    items={lifecycle.map((item) => ({
                        id: item.id,
                        title: item.event,
                        detail: item.reason ?? 'No reason recorded',
                        meta: `${item.performed_by} · ${item.when_label}`,
                        reason: null,
                    }))}
                />
            </div>

            {lifecycleAction && (
                <LifecycleModal action={lifecycleAction} user={userRecord} userOptions={userOptions} onClose={() => setLifecycleAction(null)} />
            )}
        </AppShell>
    );
}

function HistoryCard({
    title,
    items,
}: {
    title: string;
    items: Array<{ id: number; title: string; detail: string; meta: string; reason: string | null }>;
}) {
    return (
        <section className="card" style={{ padding: 22 }}>
            <div className="section-title">
                <History aria-hidden="true" /> {title}
            </div>
            {items.length === 0 ? (
                <EmptyState>No history recorded yet</EmptyState>
            ) : (
                <div className="timeline">
                    {items.map((item) => (
                        <div key={item.id} className="timeline-item">
                            <div className="timeline-dot" />
                            <div>
                                <strong>{item.title}</strong>
                                <div>{item.detail}</div>
                                {item.reason && <div style={{ color: 'var(--label)', marginTop: 3 }}>Reason: {item.reason}</div>}
                                <small>{item.meta}</small>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

function LifecycleModal({
    action,
    user,
    userOptions,
    onClose,
}: {
    action: 'delete' | 'restore';
    user: UserRecord;
    userOptions: Props['userOptions'];
    onClose: () => void;
}) {
    const form = useForm({ reason: '', replacement_user_id: '' });
    const submit = () => {
        if (action === 'restore') form.post(route('admin.users.restore', user.id), { onSuccess: onClose });
        else form.delete(route('admin.users.destroy', user.id), { onSuccess: onClose });
    };
    return (
        <Modal
            title={action === 'restore' ? `Restore ${user.full_name}` : `Delete ${user.full_name} safely`}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        className={`btn ${action === 'restore' ? 'btn-primary' : 'btn-danger'}`}
                        disabled={form.processing || !form.data.reason.trim()}
                        onClick={submit}
                    >
                        {action === 'restore' ? 'Restore account' : 'Delete and preserve history'}
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={form.errors} />
            <p style={{ color: 'var(--muted)', marginBottom: 16 }}>
                {action === 'restore'
                    ? 'The account will regain sign-in access. Historical records are unchanged.'
                    : 'This is a soft deletion. Assignments, reports, approvals and audit history remain intact.'}
            </p>
            {action === 'delete' && (
                <div className="field">
                    <label htmlFor="replacement">Replacement for open work</label>
                    <select
                        id="replacement"
                        value={form.data.replacement_user_id}
                        onChange={(e) => form.setData('replacement_user_id', e.target.value)}
                    >
                        <option value="">Select if the user holds open assignments</option>
                        {userOptions.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.full_name}
                                {item.title ? ` — ${item.title}` : ''}
                            </option>
                        ))}
                    </select>
                    {form.errors.replacement_user_id && <div className="field-error">{form.errors.replacement_user_id}</div>}
                </div>
            )}
            <div className="field">
                <label htmlFor="lifecycle-reason">Reason *</label>
                <textarea id="lifecycle-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                {form.errors.reason && <div className="field-error">{form.errors.reason}</div>}
            </div>
        </Modal>
    );
}
