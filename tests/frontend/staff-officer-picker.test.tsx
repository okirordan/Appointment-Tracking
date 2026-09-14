import StaffOfficerPicker, { type StaffOfficer } from '@/components/ats/staff-officer-picker';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const officer: StaffOfficer = { id: 12, full_name: 'Jane Namusoke', title: 'Senior Education Officer' };
function Form({ submit }: { submit: () => void }) {
    const [value, setValue] = useState<StaffOfficer | null>(null);
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <StaffOfficerPicker label="To — Officer Title" hint="Officer title receiving the instruction." value={value} onSelect={setValue} />
            <output aria-label="Selected officer">{value?.id ?? 'No officer'}</output>
        </form>
    );
}
beforeEach(() => {
    vi.stubGlobal('route', () => '/tasks/assignee-search');
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(JSON.stringify({ users: [officer] }))),
    );
});
afterEach(() => vi.unstubAllGlobals());

it('selects a staff member by keyboard without submitting and clears the old identity when edited', async () => {
    const user = userEvent.setup();
    const submit = vi.fn();
    render(<Form submit={submit} />);
    const field = screen.getByRole('combobox', { name: 'To — Officer Title' });
    await user.type(field, 'Senior');
    await screen.findByRole('option', { name: /Jane Namusoke/ });
    await user.keyboard('{ArrowDown}{Enter}');
    expect(screen.getByRole('status', { name: 'Selected officer' })).toHaveTextContent('12');
    expect(field).toHaveValue('Senior Education Officer — Jane Namusoke');
    expect(submit).not.toHaveBeenCalled();
    await user.type(field, 'x');
    expect(screen.getByRole('status', { name: 'Selected officer' })).toHaveTextContent('No officer');
});

it('searches issuing officers using the staff directory including the current user', async () => {
    const user = userEvent.setup();
    render(
        <StaffOfficerPicker
            label="From — Officer Title"
            hint="Officer title issuing the instruction."
            purpose="origin"
            value={null}
            onSelect={vi.fn()}
        />,
    );
    await user.type(screen.getByRole('combobox'), 'Jane');
    await screen.findByRole('option');
    expect(fetch).toHaveBeenLastCalledWith('/tasks/assignee-search?q=Jane&purpose=origin', expect.anything());
});

it('shows an actionable error when staff search fails', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response('', { status: 500 }));
    const user = userEvent.setup();
    render(<Form submit={vi.fn()} />);
    await user.type(screen.getByRole('combobox'), 'Jane');
    expect(await screen.findByRole('alert')).toHaveTextContent('Staff search is unavailable');
});
