import MailNamedOfficerPicker, { type MailNamedOfficerOption } from '@/components/ats/mail-named-officer-picker';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const officer: MailNamedOfficerOption = { id: 9, full_name: 'Grace Atim' };

function Form() {
    const [selected, setSelected] = useState<MailNamedOfficerOption | null>(null);
    return (
        <form>
            <MailNamedOfficerPicker selected={selected} onSelect={setSelected} />
            <output aria-label="Selected name">{selected?.full_name ?? 'No name'}</output>
        </form>
    );
}

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (_url: string, init?: RequestInit) =>
                new Response(JSON.stringify(init?.method === 'POST' ? { officer } : { officers: [] }), {
                    headers: { 'Content-Type': 'application/json' },
                }),
        ),
    );
});

afterEach(() => vi.unstubAllGlobals());

it('saves a missing officer name and selects it without submitting the mail form', async () => {
    const user = userEvent.setup();
    document.cookie = 'XSRF-TOKEN=encrypted%3Dtoken; path=/';
    try {
        render(<Form />);
        await user.type(screen.getByRole('combobox', { name: 'Officer name' }), 'Grace Atim');
        await user.click(await screen.findByRole('button', { name: 'Save Grace Atim and select' }));

        expect(await screen.findByRole('status', { name: 'Selected name' })).toHaveTextContent('Grace Atim');
        expect(vi.mocked(fetch)).toHaveBeenCalledWith(
            '/mail.named-officers.store',
            expect.objectContaining({
                method: 'POST',
                credentials: 'same-origin',
                headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'encrypted=token' }),
                body: JSON.stringify({ full_name: 'Grace Atim' }),
            }),
        );
    } finally {
        document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
    }
});

it('selects a previously saved officer name without creating another entry', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ officers: [officer] }), { headers: { 'Content-Type': 'application/json' } }));
    const user = userEvent.setup();
    render(<Form />);
    await user.type(screen.getByRole('combobox', { name: 'Officer name' }), 'Grace');
    await user.click(await screen.findByRole('option', { name: 'Grace Atim' }));

    expect(screen.getByRole('status', { name: 'Selected name' })).toHaveTextContent('Grace Atim');
    await waitFor(() => expect(vi.mocked(fetch).mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(0));
});
