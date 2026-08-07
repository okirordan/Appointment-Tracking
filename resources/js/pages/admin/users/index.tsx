import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import Pagination from '@/components/ats/pagination';
import { Check, UserPlus } from '@/components/icons';
import { useConfirm } from '@/hooks/use-confirm';
import { pushCredential } from '@/lib/credential';
import type { PaginatedData, SelectOption, SharedData, TempCredential } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface FlashPage {
    props: { flash?: { temp_credential?: TempCredential | null } };
}

// The one-time password is read from the action's own response page (which
// is always fresh) and handed to the copyable dialog via the credential bus.
function emitCredentialFrom(page: unknown): void {
    const credential = (page as FlashPage).props.flash?.temp_credential;
    if (credential) {
        pushCredential(credential);
    }
}

interface UserRow {
    id: number;
    full_name: string;
    title: string | null;
    username: string;
    role_label: string;
    department_name: string;
    division_name: string;
    active: boolean;
    deleted: boolean;
    deleted_at_label: string | null;
    supervisor_name: string | null;
    locked: boolean;
    force_password_change: boolean;
    failed_login_count: number;
    last_login_label: string;
    password_changed_label: string;
    password_reset_label: string;
}

interface Props {
    search: string;
    users: PaginatedData<UserRow>;
    roleOptions: SelectOption[];
    departmentOptions: { id: number; name: string }[];
    divisionOptions: { id: number; name: string; department_id: number }[];
    unitOptions: { id: number; name: string; type: string; department_id: number; division_id: number | null }[];
    positionOptions: { id: number; title: string; organizational_unit_id: number; role_id: number }[];
}

export default function UsersIndex({ search, users, roleOptions, departmentOptions, divisionOptions, unitOptions, positionOptions }: Props) {
    const [q, setQ] = useState(search);
    const [showNewUser, setShowNewUser] = useState(false);
    const [passwordUser, setPasswordUser] = useState<UserRow | null>(null);
    const { auth } = usePage<SharedData>().props;
    const confirm = useConfirm();

    const toggleActive = async (user: UserRow) => {
        const ok = await confirm({
            title: user.active ? `Deactivate ${user.full_name}?` : `Activate ${user.full_name}?`,
            message: user.active
                ? 'They will immediately lose access to ATS. Their tasks and history are preserved.'
                : 'They will regain access to ATS at their next sign-in.',
            confirmLabel: user.active ? 'Deactivate' : 'Activate',
            variant: user.active ? 'danger' : 'default',
        });
        if (ok) {
            router.post(route('admin.users.toggle-active', user.id), {}, { preserveScroll: true });
        }
    };

    useEffect(() => {
        // Quick action "Create User Account" deep-links with ?new=1.
        if (new URLSearchParams(window.location.search).get('new') === '1') {
            setShowNewUser(true);
        }
    }, []);

    const applySearch = () => {
        router.get(route('admin.users.index'), q === '' ? {} : { q }, { preserveState: true });
    };

    return (
        <AppShell title="User Management">
            <div className="page-hd">
                <div>
                    <h1>User Management</h1>
                </div>
                <button type="button" className="btn btn-primary" onClick={() => setShowNewUser(true)}>
                    <UserPlus aria-hidden="true" />
                    Add User
                </button>
            </div>
            <div className="filters-bar">
                <input
                    className="input"
                    style={{ width: 260 }}
                    type="text"
                    placeholder="Search by name or username…"
                    aria-label="Search users"
                    value={q}
                    onChange={(event) => setQ(event.target.value)}
                    onBlur={() => q !== search && applySearch()}
                    onKeyDown={(event) => event.key === 'Enter' && applySearch()}
                />
            </div>
            <div className="card">
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Full Name / Title</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Division</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.map((user) => (
                                <tr key={user.id}>
                                    <td>
                                        <div style={{ fontWeight: 600 }}>{user.full_name}</div>
                                        <div style={{ fontSize: 12, color: 'var(--label)' }}>{user.title}</div>
                                    </td>
                                    <td className="ref">{user.username}</td>
                                    <td>{user.role_label}</td>
                                    <td>{user.department_name}</td>
                                    <td>{user.division_name}</td>
                                    <td>
                                        <span className={`badge ${user.locked ? 'pr-urgent' : user.active ? 'st-completed' : 'st-archived'}`}>
                                            {user.deleted ? 'Deleted' : user.locked ? 'Locked' : user.active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td>
                                        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                                            <button
                                                type="button"
                                                className="btn btn-ghost"
                                                style={{ padding: '6px 12px', fontSize: 12 }}
                                                onClick={() => router.get(route('admin.users.show', user.id))}
                                            >
                                                Profile & history
                                            </button>
                                            {!user.deleted && (
                                                <button
                                                    type="button"
                                                    className="btn btn-ghost"
                                                    style={{ padding: '6px 12px', fontSize: 12 }}
                                                    onClick={() => setPasswordUser(user)}
                                                >
                                                    Password
                                                </button>
                                            )}
                                            {user.id !== auth.user!.id && !user.deleted && (
                                                <button
                                                    type="button"
                                                    className="btn btn-ghost"
                                                    style={{ padding: '6px 12px', fontSize: 12 }}
                                                    onClick={() => toggleActive(user)}
                                                >
                                                    {user.active ? 'Deactivate' : 'Activate'}
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {users.data.length === 0 && <EmptyState>No matching users</EmptyState>}
                <Pagination meta={users.meta} />
            </div>

            {showNewUser && (
                <NewUserModal
                    roleOptions={roleOptions}
                    departmentOptions={departmentOptions}
                    divisionOptions={divisionOptions}
                    unitOptions={unitOptions}
                    positionOptions={positionOptions}
                    onClose={() => setShowNewUser(false)}
                />
            )}

            {passwordUser !== null && (
                <PasswordModal user={passwordUser} isSelf={passwordUser.id === auth.user!.id} onClose={() => setPasswordUser(null)} />
            )}
        </AppShell>
    );
}

function PasswordModal({ user, isSelf, onClose }: { user: UserRow; isSelf: boolean; onClose: () => void }) {
    const confirm = useConfirm();

    const act =
        (routeName: string, options: { title: string; message: string; confirmLabel: string; variant?: 'default' | 'danger' }) => async () => {
            const ok = await confirm(options);
            if (ok) {
                router.post(
                    route(routeName, user.id),
                    {},
                    {
                        preserveScroll: true,
                        onSuccess: (page) => {
                            emitCredentialFrom(page);
                            onClose();
                        },
                    },
                );
            }
        };

    return (
        <Modal title={`Password Management — ${user.full_name}`} onClose={onClose}>
            <div className="meta-grid">
                <div>
                    <span>Username</span>
                    {user.username}
                </div>
                <div>
                    <span>Role</span>
                    {user.role_label}
                </div>
                <div>
                    <span>Last Sign-in</span>
                    {user.last_login_label}
                </div>
                <div>
                    <span>Failed Attempts</span>
                    {user.failed_login_count}
                </div>
                <div>
                    <span>Password Changed</span>
                    {user.password_changed_label}
                </div>
                <div>
                    <span>Last Admin Reset</span>
                    {user.password_reset_label}
                </div>
                <div>
                    <span>Locked</span>
                    {user.locked ? 'Yes' : 'No'}
                </div>
                <div>
                    <span>Must Change Password</span>
                    {user.force_password_change ? 'Yes' : 'No'}
                </div>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 6 }}>
                <button
                    type="button"
                    className="btn btn-ghost"
                    style={{ justifyContent: 'flex-start' }}
                    onClick={act('admin.users.reset-password', {
                        title: `Reset ${user.full_name}'s password?`,
                        message: 'A one-time temporary password will be generated and shown once. The user must set a new password at next sign-in.',
                        confirmLabel: 'Reset password',
                    })}
                >
                    Reset password (generates a temporary password)
                </button>
                {!user.force_password_change && (
                    <button
                        type="button"
                        className="btn btn-ghost"
                        style={{ justifyContent: 'flex-start' }}
                        onClick={act('admin.users.force-change', {
                            title: `Require a password change?`,
                            message: `${user.full_name} will be required to set a new password the next time they sign in.`,
                            confirmLabel: 'Require change',
                        })}
                    >
                        Require password change at next sign-in
                    </button>
                )}
                {!isSelf && (
                    <button
                        type="button"
                        className="btn btn-ghost"
                        style={{ justifyContent: 'flex-start', color: user.locked ? 'var(--succ)' : 'var(--err)' }}
                        onClick={act('admin.users.toggle-lock', {
                            title: user.locked ? `Unlock ${user.full_name}'s account?` : `Lock ${user.full_name}'s account?`,
                            message: user.locked
                                ? 'They will be able to sign in again once unlocked.'
                                : 'They will be prevented from signing in until an administrator unlocks the account.',
                            confirmLabel: user.locked ? 'Unlock' : 'Lock',
                            variant: user.locked ? 'default' : 'danger',
                        })}
                    >
                        {user.locked ? 'Unlock account' : 'Lock account'}
                    </button>
                )}
            </div>
        </Modal>
    );
}

function NewUserModal({
    roleOptions,
    departmentOptions,
    divisionOptions,
    unitOptions,
    positionOptions,
    onClose,
}: {
    roleOptions: SelectOption[];
    departmentOptions: { id: number; name: string }[];
    divisionOptions: { id: number; name: string; department_id: number }[];
    unitOptions: { id: number; name: string; type: string; department_id: number; division_id: number | null }[];
    positionOptions: { id: number; title: string; organizational_unit_id: number; role_id: number }[];
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        full_name: '',
        title: '',
        role_id: roleOptions[0]?.value ?? '',
        department_id: '' as string | number,
        division_id: '' as string | number,
        organizational_unit_id: '' as string | number,
        position_id: '' as string | number,
        employee_number: '',
        email: '',
        username: '',
    });

    // Prototype behaviour: username derived from the full name, but the
    // administrator can review and change it before saving (PRD §12.13).
    const onFullNameChange = (value: string) => {
        const parts = value.trim().toLowerCase().split(/\s+/).filter(Boolean);
        const derived = parts.length > 0 ? (parts[0][0] ?? '') + (parts[1] ?? '') : '';
        setData((current) => ({ ...current, full_name: value, username: derived }));
    };

    const submit = () => {
        post(route('admin.users.store'), {
            onSuccess: (page) => {
                emitCredentialFrom(page);
                onClose();
            },
        });
    };

    const availableUnits = unitOptions.filter(
        (item) =>
            String(item.department_id) === String(data.department_id) &&
            (data.division_id ? String(item.division_id) === String(data.division_id) : item.division_id === null),
    );
    const availablePositions = positionOptions.filter((item) => String(item.organizational_unit_id) === String(data.organizational_unit_id));

    const selectPosition = (value: string) => {
        const position = positionOptions.find((item) => String(item.id) === value);
        setData((current) => ({
            ...current,
            position_id: value,
            title: position?.title ?? current.title,
            role_id: position ? String(position.role_id) : current.role_id,
        }));
    };

    return (
        <Modal
            title="Add User"
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="button" className="btn btn-primary" disabled={processing} onClick={submit}>
                        <Check aria-hidden="true" />
                        Create Account
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={errors} />
            <div className="field">
                <label htmlFor="nu-name">Full Name *</label>
                <input id="nu-name" type="text" value={data.full_name} onChange={(event) => onFullNameChange(event.target.value)} />
                {errors.full_name && <div className="field-error">{errors.full_name}</div>}
            </div>
            <div className="two-col">
                <div className="field">
                    <label htmlFor="nu-role">Role *</label>
                    <select id="nu-role" value={data.role_id} onChange={(event) => setData('role_id', event.target.value)}>
                        {roleOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    {errors.role_id && <div className="field-error">{errors.role_id}</div>}
                </div>
                <div className="field">
                    <label htmlFor="nu-dept">Department</label>
                    <select
                        id="nu-dept"
                        value={data.department_id}
                        onChange={(event) =>
                            setData((current) => ({
                                ...current,
                                department_id: event.target.value,
                                division_id: '',
                                organizational_unit_id: '',
                                position_id: '',
                                title: '',
                            }))
                        }
                    >
                        <option value="">None (central)</option>
                        {departmentOptions.map((department) => (
                            <option key={department.id} value={String(department.id)}>
                                {department.name}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <div className="two-col">
                <div className="field">
                    <label htmlFor="nu-division">Division</label>
                    <select
                        id="nu-division"
                        value={data.division_id}
                        onChange={(event) =>
                            setData((current) => ({
                                ...current,
                                division_id: event.target.value,
                                organizational_unit_id: '',
                                position_id: '',
                                title: '',
                            }))
                        }
                    >
                        <option value="">None / central / legacy</option>
                        {divisionOptions
                            .filter((item) => String(item.department_id) === String(data.department_id))
                            .map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                    </select>
                    {errors.division_id && <div className="field-error">{errors.division_id}</div>}
                </div>
            </div>
            <div className="two-col">
                <div className="field">
                    <label htmlFor="nu-unit">Unit / Office / Section</label>
                    <select
                        id="nu-unit"
                        value={data.organizational_unit_id}
                        disabled={!data.department_id}
                        onChange={(event) =>
                            setData((current) => ({ ...current, organizational_unit_id: event.target.value, position_id: '', title: '' }))
                        }
                    >
                        <option value="">Select unit</option>
                        {availableUnits.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                    {errors.organizational_unit_id && <div className="field-error">{errors.organizational_unit_id}</div>}
                </div>
                <div className="field">
                    <label htmlFor="nu-position">Approved Position</label>
                    <select
                        id="nu-position"
                        value={data.position_id}
                        disabled={!data.organizational_unit_id}
                        onChange={(event) => selectPosition(event.target.value)}
                    >
                        <option value="">Select approved position</option>
                        {availablePositions.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.title}
                            </option>
                        ))}
                    </select>
                    {errors.position_id && <div className="field-error">{errors.position_id}</div>}
                </div>
            </div>
            <div className="two-col">
                <div className="field">
                    <label htmlFor="nu-title">Title / Designation</label>
                    <input id="nu-title" type="text" value={data.title} onChange={(event) => setData('title', event.target.value)} />
                </div>
                <div className="field">
                    <label htmlFor="nu-employee">Staff ID</label>
                    <input id="nu-employee" value={data.employee_number} onChange={(event) => setData('employee_number', event.target.value)} />
                    {errors.employee_number && <div className="field-error">{errors.employee_number}</div>}
                </div>
            </div>
            <div className="two-col">
                <div className="field">
                    <label htmlFor="nu-username">Username</label>
                    <input id="nu-username" type="text" value={data.username} onChange={(event) => setData('username', event.target.value)} />
                    {errors.username && <div className="field-error">{errors.username}</div>}
                </div>
                <div className="field">
                    <label htmlFor="nu-email">Official email</label>
                    <input
                        id="nu-email"
                        type="email"
                        placeholder="firstname.lastname@education.go.ug"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                    />
                    {errors.email && <div className="field-error">{errors.email}</div>}
                </div>
            </div>
        </Modal>
    );
}
