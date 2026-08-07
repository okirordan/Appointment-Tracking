import AppShell from '@/components/ats/app-shell';
import { router, useForm } from '@inertiajs/react';
export default function Divisions({
    divisions,
    departments,
}: {
    divisions: { id: number; name: string; code: string; department_id: number; department_name: string; active: boolean; staff_count: number }[];
    departments: { id: number; name: string }[];
}) {
    const form = useForm({ department_id: '', name: '', code: '' });
    return (
        <AppShell title="Divisions">
            <div className="page-hd">
                <div>
                    <h1>Divisions</h1>
                </div>
            </div>
            <div className="grid2">
                <form
                    className="card"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('admin.divisions.store'), { onSuccess: () => form.reset() });
                    }}
                >
                    <h3>Add division</h3>
                    <div className="field">
                        <label>Department</label>
                        <select value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)} required>
                            <option value="">Select department</option>
                            {departments.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="field">
                        <label>Name</label>
                        <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                    </div>
                    <div className="field">
                        <label>Code</label>
                        <input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} required />
                    </div>
                    <button className="btn btn-primary">Create division</button>
                </form>
                <div className="card">
                    <h3>Active and historical divisions</h3>
                    {divisions.map((d) => (
                        <div className="drill-row" key={d.id}>
                            <span>
                                <strong>{d.name}</strong>
                                <small>
                                    {d.department_name} · {d.code} · {d.staff_count} staff
                                </small>
                            </span>
                            <button className="btn btn-ghost" onClick={() => router.post(route('admin.divisions.toggle-active', d.id))}>
                                {d.active ? 'Deactivate' : 'Activate'}
                            </button>
                        </div>
                    ))}
                </div>
            </div>
        </AppShell>
    );
}
