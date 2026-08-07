import AppShell from '@/components/ats/app-shell';
import EmptyState from '@/components/ats/empty-state';
import FormErrorSummary from '@/components/ats/form-error-summary';
import Modal from '@/components/ats/modal';
import { Building2, Check } from '@/components/icons';
import { useConfirm } from '@/hooks/use-confirm';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface DepartmentRow {
    id: number;
    name: string;
    code: string;
    head_name: string | null;
    active: boolean;
    officer_count: number;
    user_count: number;
    task_count: number;
}

interface Props {
    departments: DepartmentRow[];
}

export default function DepartmentsIndex({ departments }: Props) {
    const [editing, setEditing] = useState<DepartmentRow | 'new' | null>(null);
    const confirm = useConfirm();

    const toggleActive = async (department: DepartmentRow) => {
        const ok = await confirm({
            title: department.active ? `Deactivate ${department.name}?` : `Reactivate ${department.name}?`,
            message: department.active
                ? 'The department is hidden from new assignments. Its users, tasks, and history are preserved and keep their association.'
                : 'The department becomes available for new assignments again.',
            confirmLabel: department.active ? 'Deactivate' : 'Reactivate',
            variant: department.active ? 'danger' : 'default',
        });
        if (ok) {
            router.post(route('admin.departments.toggle-active', department.id), {}, { preserveScroll: true });
        }
    };

    return (
        <AppShell title="Departments">
            <div className="page-hd">
                <div>
                    <h1>Departments</h1>
                </div>
                <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}>
                    <Building2 aria-hidden="true" />
                    Add Department
                </button>
            </div>
            <div className="card">
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Head</th>
                                <th>Active Officers</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {departments.map((department) => (
                                <tr key={department.id}>
                                    <td style={{ fontWeight: 600 }}>{department.name}</td>
                                    <td className="ref">{department.code}</td>
                                    <td>{department.head_name ?? '—'}</td>
                                    <td>{department.officer_count}</td>
                                    <td>
                                        <span className={`badge ${department.active ? 'st-completed' : 'st-archived'}`}>
                                            {department.active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td>
                                        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                                            <button
                                                type="button"
                                                className="btn btn-ghost"
                                                style={{ padding: '6px 12px', fontSize: 12 }}
                                                onClick={() => setEditing(department)}
                                            >
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-ghost"
                                                style={{ padding: '6px 12px', fontSize: 12 }}
                                                title={
                                                    department.user_count > 0 || department.task_count > 0
                                                        ? 'Departments with users or tasks are deactivated, never deleted'
                                                        : undefined
                                                }
                                                onClick={() => toggleActive(department)}
                                            >
                                                {department.active ? 'Deactivate' : 'Activate'}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {departments.length === 0 && <EmptyState>No departments yet — add the first one</EmptyState>}
            </div>

            {editing !== null && <DepartmentModal department={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </AppShell>
    );
}

function DepartmentModal({ department, onClose }: { department: DepartmentRow | null; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: department?.name ?? '',
        code: department?.code ?? '',
        head_name: department?.head_name ?? '',
    });

    const submit = () => {
        if (department === null) {
            post(route('admin.departments.store'), { onSuccess: onClose });
        } else {
            put(route('admin.departments.update', department.id), { onSuccess: onClose });
        }
    };

    return (
        <Modal
            title={department === null ? 'Add Department' : `Edit ${department.name}`}
            onClose={onClose}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="button" className="btn btn-primary" disabled={processing} onClick={submit}>
                        <Check aria-hidden="true" />
                        {department === null ? 'Create Department' : 'Save Changes'}
                    </button>
                </>
            }
        >
            <FormErrorSummary errors={errors} />
            <div className="field">
                <label htmlFor="dp-name">Department Name *</label>
                <input id="dp-name" type="text" value={data.name} onChange={(event) => setData('name', event.target.value)} />
                {errors.name && <div className="field-error">{errors.name}</div>}
            </div>
            <div className="field">
                <label htmlFor="dp-code">Department Code *</label>
                <input
                    id="dp-code"
                    type="text"
                    placeholder="e.g. BSE"
                    value={data.code}
                    onChange={(event) => setData('code', event.target.value.toUpperCase())}
                />
                {errors.code && <div className="field-error">{errors.code}</div>}
            </div>
            <div className="field">
                <label htmlFor="dp-head">Head of Department</label>
                <input id="dp-head" type="text" value={data.head_name} onChange={(event) => setData('head_name', event.target.value)} />
            </div>
        </Modal>
    );
}
