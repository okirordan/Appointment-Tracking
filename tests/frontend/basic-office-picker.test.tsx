import BasicOfficePicker from '@/pages/mail/basic-office-picker';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

afterEach(() => vi.unstubAllGlobals());

it('searches full names, selects shorthand with a stable identity, and clears identity on custom editing', async () => {
    const onChange = vi.fn();
    const fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ offices: [{ key: 'title:24', value: 'C/LEIT', label: 'C/LEIT — Commissioner Library', kind: 'Title' }] }),
    });
    vi.stubGlobal('fetch', fetch);
    function Example() {
        const [value, setValue] = useState('');
        return (
            <BasicOfficePicker
                id="office"
                value={value}
                endpoint="/mail-directory"
                onChange={(value, key) => {
                    setValue(value);
                    onChange(value, key);
                }}
            />
        );
    }
    render(<Example />);
    const input = screen.getByRole('combobox');
    fireEvent.change(input, { target: { value: 'Commissioner Library' } });
    await screen.findByRole('option', { name: /C\/LEIT/ });
    expect(fetch.mock.calls[0][0]).toBe('/mail-directory?q=Commissioner+Library&linked=1');
    fireEvent.keyDown(input, { key: 'ArrowDown' });
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(input).toHaveValue('C/LEIT');
    expect(onChange).toHaveBeenLastCalledWith('C/LEIT', 'title:24');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    fireEvent.change(input, { target: { value: 'Custom field office' } });
    expect(onChange).toHaveBeenLastCalledWith('Custom field office', '');
    fireEvent.keyDown(input, { key: 'Escape' });
});

it('reports a directory failure while preserving the custom office input', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));
    render(<BasicOfficePicker id="office" value="Regional office" endpoint="/mail-directory" onChange={vi.fn()} />);
    fireEvent.focus(screen.getByRole('combobox'));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Directory unavailable'));
    expect(screen.getByRole('combobox')).toHaveValue('Regional office');
});

it('offers a new source in the existing dropdown and keeps its name until the form saves', async () => {
    const onChange = vi.fn();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ offices: [] }) }));
    function Example() {
        const [value, setValue] = useState('');
        return (
            <BasicOfficePicker
                id="source"
                value={value}
                endpoint="/mail-directory"
                source
                onChange={(name, key) => {
                    setValue(name);
                    onChange(name, key);
                }}
            />
        );
    }
    render(<Example />);
    const input = screen.getByRole('combobox');
    fireEvent.change(input, { target: { value: 'Uganda National ICT Association' } });
    const create = await screen.findByRole('option', { name: /Add New Source: Uganda National ICT Association/ });
    fireEvent.click(create);
    expect(onChange).toHaveBeenLastCalledWith('Uganda National ICT Association', 'new');
    expect(input).toHaveValue('Uganda National ICT Association');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    await waitFor(() => expect(vi.mocked(fetch).mock.calls[0][0]).toContain('purpose=source'));
});

it('offers an unlisted correspondence recipient without creating a source', async () => {
    const onChange = vi.fn();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ offices: [] }) }));
    function Example() {
        const [value, setValue] = useState('');
        return (
            <BasicOfficePicker
                id="office"
                value={value}
                endpoint="/mail-directory"
                allowNewRecipient
                onChange={(name, key) => {
                    setValue(name);
                    onChange(name, key);
                }}
            />
        );
    }
    render(<Example />);
    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'Regional ICT Association' } });
    fireEvent.click(await screen.findByRole('option', { name: /Add New Recipient: Regional ICT Association/ }));
    expect(onChange).toHaveBeenLastCalledWith('Regional ICT Association', 'new-recipient');
    expect(screen.queryByText(/Add New Source/)).not.toBeInTheDocument();
});
